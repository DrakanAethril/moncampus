<?php

declare(strict_types=1);

namespace App\Controller\Equipment;

use App\Attribute\RequiresFeature;
use App\Entity\EquipmentType;
use App\Entity\Room;
use App\Enum\EquipmentIncidentCause;
use App\Enum\EquipmentItemStatus;
use App\Enum\EquipmentMovementKind;
use App\Enum\Feature;
use App\Repository\EquipmentItemRepository;
use App\Repository\EquipmentTypeRepository;
use App\Service\Equipment\EquipmentLedger;
use App\Service\Equipment\EquipmentStockException;
use App\Service\FormValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The incidents of Gestion > Matériel: « Déclarer un problème », and what answers one - « Retrouvé »,
 * « Réparé », « Mettre au rebut ».
 *
 * Every gesture comes from a fiche and goes back to it; the rules (which count a piece leaves,
 * which incident a found piece answers) are EquipmentLedger's.
 */
#[IsGranted(new Expression(self::ACCESS_EXPRESSION))]
#[RequiresFeature(Feature::Equipment)]
class IncidentController extends AbstractController
{
    use EquipmentTrait;

    #[Route(path: '/equipment/items/{id}/incident', name: 'app_equipment_item_incident', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function declareForItem(int $id, Request $request, EquipmentItemRepository $items, EquipmentLedger $ledger): Response
    {
        $item = $items->find($id) ?? throw $this->createNotFoundException();
        $form = $this->incidentForm($this->generateUrl('app_equipment_item_incident', ['id' => $id]), false);
        $form->handleRequest($request);

        if ($this->isValid($form)) {
            [$kind, $cause, $room, $day] = $this->readIncident($form);

            try {
                $ledger->declareItemIncident($item, $kind, $cause, $room, $this->occurredAt($day), FormValue::trimmed($form, 'note'), $this->currentUser());
                $this->addFlash('success', 'equipmentIncidentDeclaredFlashMessage');
            } catch (EquipmentStockException $exception) {
                $this->flashRefusal($exception);
            }
        }

        return $this->redirectToRoute('app_equipment_item_show', ['id' => $id]);
    }

    /** « Retrouvé », « Réparé » or « Mettre au rebut » - the one button the piece's status offers. */
    #[Route(path: '/equipment/items/{id}/{answer}', name: 'app_equipment_item_answer', requirements: ['id' => '\d+', 'answer' => 'found|repaired|disposed'], methods: ['POST'])]
    public function answerForItem(int $id, string $answer, Request $request, EquipmentItemRepository $items, EquipmentLedger $ledger): Response
    {
        $item = $items->find($id) ?? throw $this->createNotFoundException();
        $this->assertValidEquipmentToken('equipment_item_answer', $request);
        $note = trim($request->request->getString('note'));
        $note = '' === $note ? null : $note;

        try {
            $kind = EquipmentMovementKind::from($answer);

            if (EquipmentMovementKind::Disposed === $kind) {
                $ledger->disposeItem($item, $note, $this->currentUser());
            } else {
                $ledger->resolveItem($item, $kind, $note, $this->currentUser());
            }

            $this->addFlash('success', 'equipmentAnswerRecordedFlashMessage');
        } catch (EquipmentStockException $exception) {
            $this->flashRefusal($exception);
        }

        return $this->redirectToRoute('app_equipment_item_show', ['id' => $id]);
    }

    #[Route(path: '/equipment/types/{id}/incident', name: 'app_equipment_type_incident', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function declareForQuantity(int $id, Request $request, EquipmentTypeRepository $types, EquipmentLedger $ledger): Response
    {
        $type = $this->quantityTypeOrNotFound($types, $id);
        $form = $this->incidentForm($this->generateUrl('app_equipment_type_incident', ['id' => $id]), true);
        $form->handleRequest($request);

        if ($this->isValid($form)) {
            [$kind, $cause, $room, $day] = $this->readIncident($form);
            $origin = $form->get('origin')->getData();

            try {
                $ledger->declareQuantityIncident(
                    $type,
                    $kind,
                    FormValue::int($form, 'quantity'),
                    $origin instanceof EquipmentItemStatus ? $origin : EquipmentItemStatus::InUse,
                    $cause,
                    $room,
                    $this->occurredAt($day),
                    FormValue::trimmed($form, 'note'),
                    $this->currentUser(),
                );
                $this->addFlash('success', 'equipmentIncidentDeclaredFlashMessage');
            } catch (EquipmentStockException $exception) {
                $this->flashRefusal($exception);
            }
        }

        return $this->redirectToRoute('app_equipment_type_show', ['id' => $id]);
    }

    #[Route(path: '/equipment/types/{id}/resolve', name: 'app_equipment_type_resolve', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function answerForQuantity(int $id, Request $request, EquipmentTypeRepository $types, EquipmentLedger $ledger): Response
    {
        $type = $this->quantityTypeOrNotFound($types, $id);
        $form = $this->resolutionForm($this->generateUrl('app_equipment_type_resolve', ['id' => $id]));
        $form->handleRequest($request);

        if ($this->isValid($form)) {
            $kind = $form->get('kind')->getData();

            try {
                $ledger->resolveQuantity(
                    $type,
                    $kind instanceof EquipmentMovementKind ? $kind : EquipmentMovementKind::Repaired,
                    FormValue::int($form, 'quantity'),
                    FormValue::trimmed($form, 'note'),
                    $this->currentUser(),
                );
                $this->addFlash('success', 'equipmentAnswerRecordedFlashMessage');
            } catch (EquipmentStockException $exception) {
                $this->flashRefusal($exception);
            }
        }

        return $this->redirectToRoute('app_equipment_type_show', ['id' => $id]);
    }

    /** A form that does not validate says why, on the fiche it came from. */
    private function isValid(FormInterface $form): bool
    {
        if (!$form->isSubmitted()) {
            return false;
        }

        if (!$form->isValid()) {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('danger', $error->getMessage());
            }

            return false;
        }

        return true;
    }

    /**
     * @return array{EquipmentMovementKind, EquipmentIncidentCause, Room|null, \DateTimeImmutable}
     */
    private function readIncident(FormInterface $form): array
    {
        $kind = $form->get('kind')->getData();
        $cause = $form->get('cause')->getData();
        $room = $form->get('room')->getData();
        $day = $form->get('occurredAt')->getData();

        return [
            $kind instanceof EquipmentMovementKind ? $kind : EquipmentMovementKind::OutOfOrder,
            $cause instanceof EquipmentIncidentCause ? $cause : EquipmentIncidentCause::Unknown,
            $room instanceof Room ? $room : null,
            $day instanceof \DateTimeImmutable ? $day : new \DateTimeImmutable('today'),
        ];
    }

    private function quantityTypeOrNotFound(EquipmentTypeRepository $types, int $id): EquipmentType
    {
        $type = $types->find($id);

        if (!$type instanceof EquipmentType || $type->isUnitTracked()) {
            throw $this->createNotFoundException();
        }

        return $type;
    }
}
