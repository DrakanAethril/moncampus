<?php

declare(strict_types=1);

namespace App\Controller\Equipment;

use App\Attribute\RequiresFeature;
use App\Entity\EquipmentType;
use App\Enum\Feature;
use App\Repository\EquipmentMovementRepository;
use App\Repository\EquipmentTypeRepository;
use App\Service\Equipment\EquipmentLedger;
use App\Service\Equipment\EquipmentReorderLine;
use App\Service\Equipment\EquipmentReorderPlanner;
use App\Service\Equipment\EquipmentStockException;
use App\Service\FormValue;
use App\Service\GotenbergClient;
use App\Service\GotenbergPageSetup;
use App\Service\GotenbergUnavailableException;
use App\Service\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion > Matériel > À commander, and the three order gestures - commander, réceptionner,
 * annuler.
 *
 * The list reads the stored counters and six months of consumption; the rule of what needs
 * ordering, and how many, is App\Service\Equipment\EquipmentReorderPlanner's. It exports as CSV
 * (to paste into a purchase request) and as PDF (to hand over).
 */
#[IsGranted(new Expression(self::ACCESS_EXPRESSION))]
#[RequiresFeature(Feature::Equipment)]
class OrderController extends AbstractController
{
    use EquipmentTrait;

    #[Route(path: '/equipment/reorder', name: 'app_equipment_reorder', methods: ['GET'])]
    public function index(Request $request, EquipmentTypeRepository $types, EquipmentMovementRepository $movements, EquipmentReorderPlanner $planner): Response
    {
        $showAll = QueryValue::bool($request, 'all');
        $lines = $this->lines($types, $movements, $planner, !$showAll);

        $forms = [];
        foreach ($lines as $line) {
            $forms[(int) $line['type']->getId()] = $this->orderForm($line['type'], 'order', 'reorder', $line['plan']->suggested)->createView();
        }

        return $this->render('equipment/reorder.html.twig', [
            'lines' => $lines,
            'forms' => $forms,
            'showAll' => $showAll,
            'windowMonths' => EquipmentReorderPlanner::WINDOW_MONTHS,
        ]);
    }

    #[Route(path: '/equipment/reorder/export.{format}', name: 'app_equipment_reorder_export', requirements: ['format' => 'csv|pdf'], methods: ['GET'])]
    public function export(string $format, Request $request, EquipmentTypeRepository $types, EquipmentMovementRepository $movements, EquipmentReorderPlanner $planner, GotenbergClient $gotenberg): Response
    {
        // The export follows the list on screen: « À commander », or everything with `?all=1`.
        $lines = $this->lines($types, $movements, $planner, !QueryValue::bool($request, 'all'));
        $filename = \sprintf('materiel-a-commander-%s.%s', (new \DateTimeImmutable())->format('Y-m-d'), $format);

        if ('csv' === $format) {
            $handle = fopen('php://temp', 'r+');
            if (false === $handle) {
                throw new \RuntimeException('Cannot open a temporary stream.');
            }
            // A BOM, so a spreadsheet opens the accents as accents.
            fwrite($handle, "\u{FEFF}");
            fputcsv($handle, ['Matériel', 'Catégorie', 'Référence fournisseur', 'Disponibles', 'En commande', 'Stock cible', 'Quantité suggérée', 'Prix unitaire'], ';', '"', '');
            foreach ($lines as ['type' => $type, 'plan' => $plan]) {
                fputcsv($handle, [
                    $type->getName(),
                    $type->getCategory()?->getName() ?? '',
                    $type->getSupplierReference() ?? '',
                    $type->getAvailableCount(),
                    $type->getOnOrderCount(),
                    $type->getTargetStock() ?? '',
                    $plan->suggested ?? '',
                    null !== $type->getUnitPrice() ? str_replace('.', ',', $type->getUnitPrice()) : '',
                ], ';', '"', '');
            }
            rewind($handle);
            $csv = (string) stream_get_contents($handle);
            fclose($handle);

            $response = new Response($csv, Response::HTTP_OK, ['Content-Type' => 'text/csv; charset=UTF-8']);
        } else {
            try {
                $pdf = $gotenberg->convertHtmlToPdf(
                    $this->renderView('equipment/reorder_pdf.html.twig', ['lines' => $lines, 'generatedAt' => new \DateTimeImmutable()]),
                    new GotenbergPageSetup(marginTop: '12mm', marginBottom: '12mm', marginLeft: '12mm', marginRight: '12mm'),
                );
            } catch (GotenbergUnavailableException) {
                $this->addFlash('danger', 'equipmentPdfUnavailableMessage');

                return $this->redirectToRoute('app_equipment_reorder');
            }

            $response = new Response($pdf, Response::HTTP_OK, ['Content-Type' => 'application/pdf']);
        }

        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename));

        return $response;
    }

    /**
     * Commander, réceptionner, annuler. `?back=reorder` returns to the list the order was placed
     * from; otherwise, to the type's fiche.
     */
    #[Route(path: '/equipment/types/{id}/orders/{gesture}', name: 'app_equipment_type_order', requirements: ['id' => '\d+', 'gesture' => 'order|receive|cancel'], methods: ['POST'])]
    public function gesture(int $id, string $gesture, Request $request, EquipmentTypeRepository $types, EquipmentLedger $ledger): Response
    {
        $type = $types->find($id) ?? throw $this->createNotFoundException();
        $back = QueryValue::string($request, 'back');
        $form = $this->orderForm($type, $gesture, $back);
        $form->handleRequest($request);
        $redirect = 'reorder' === $back
            ? $this->redirectToRoute('app_equipment_reorder')
            : $this->redirectToRoute('app_equipment_type_show', ['id' => $id]);

        if (!$form->isSubmitted() || !$form->isValid()) {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('danger', $error->getMessage());
            }

            return $redirect;
        }

        $quantity = FormValue::int($form, 'quantity');
        $note = FormValue::trimmed($form, 'note');
        $note = '' === $note ? null : $note;

        try {
            switch ($gesture) {
                case 'order':
                    $ledger->order($type, $quantity, $note, $this->currentUser());
                    $this->addFlash('success', 'equipmentOrderedFlashMessage');
                    break;
                case 'cancel':
                    $ledger->cancelOrder($type, $quantity, $note, $this->currentUser());
                    $this->addFlash('success', 'equipmentOrderCancelledFlashMessage');
                    break;
                default:
                    $items = $ledger->receiveOrder($type, $quantity, $note, $this->currentUser());
                    $this->addFlash('success', 'equipmentOrderReceivedFlashMessage');

                    // Pieces arrived: their codes, for the Dymo, exactly as after a delivery.
                    if ([] !== $items) {
                        return $this->redirectToRoute('app_equipment_labels', [
                            'from' => $items[0]->getCodeNumber(),
                            'to' => $items[\count($items) - 1]->getCodeNumber(),
                        ]);
                    }
            }
        } catch (EquipmentStockException $exception) {
            $this->flashRefusal($exception);
        }

        return $redirect;
    }

    /**
     * @return list<array{type: EquipmentType, plan: EquipmentReorderLine}>
     */
    private function lines(EquipmentTypeRepository $types, EquipmentMovementRepository $movements, EquipmentReorderPlanner $planner, bool $onlyToOrder): array
    {
        $today = new \DateTimeImmutable('today');
        $consumption = $movements->consumptionSince($today->modify(\sprintf('-%d days', EquipmentReorderPlanner::WINDOW_DAYS)));

        $lines = [];
        foreach ($types->findForStock() as $type) {
            $plan = $planner->plan(
                $type->getAvailableCount(),
                $type->getOnOrderCount(),
                $type->getAlertThreshold(),
                $type->getTargetStock(),
                $consumption[(int) $type->getId()] ?? 0,
                $today,
            );

            if (!$onlyToOrder || $plan->needsOrder) {
                $lines[] = ['type' => $type, 'plan' => $plan];
            }
        }

        // What runs out first, first; what does not run out at all, by name after.
        usort($lines, static fn (array $a, array $b): int => [!$a['plan']->needsOrder, $a['plan']->runsOutOn ?? new \DateTimeImmutable('9999-12-31'), $a['type']->getName()]
            <=> [!$b['plan']->needsOrder, $b['plan']->runsOutOn ?? new \DateTimeImmutable('9999-12-31'), $b['type']->getName()]);

        return $lines;
    }
}
