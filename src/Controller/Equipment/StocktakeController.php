<?php

declare(strict_types=1);

namespace App\Controller\Equipment;

use App\Attribute\RequiresFeature;
use App\Entity\EquipmentItem;
use App\Entity\EquipmentStocktake;
use App\Entity\EquipmentStocktakeLine;
use App\Enum\Feature;
use App\Repository\EquipmentCategoryRepository;
use App\Repository\EquipmentItemRepository;
use App\Repository\EquipmentStocktakeLineRepository;
use App\Repository\EquipmentStocktakeRepository;
use App\Repository\EquipmentTypeRepository;
use App\Service\Equipment\EquipmentCode;
use App\Service\Equipment\EquipmentStockException;
use App\Service\Equipment\EquipmentStocktakeCloser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Gestion > Matériel > Comptage d'inventaire.
 *
 * One count open at a time. The screen is built for doing it with the pieces in hand: type the
 * label code of each piece you see - the same code the Dymo printed - and it is ticked; for the
 * quantity types, write down how many there are. Closing goes through a review that lists exactly
 * what will be written (App\Service\Equipment\EquipmentStocktakeCloser::gaps()).
 */
#[IsGranted(new Expression(self::ACCESS_EXPRESSION))]
#[RequiresFeature(Feature::Equipment)]
class StocktakeController extends AbstractController
{
    use EquipmentTrait;

    #[Route(path: '/equipment/stocktakes', name: 'app_equipment_stocktakes', methods: ['GET'])]
    public function index(EquipmentStocktakeRepository $stocktakes, EquipmentCategoryRepository $categories): Response
    {
        return $this->render('equipment/stocktakes.html.twig', [
            'open' => $stocktakes->findOpen(),
            'history' => $stocktakes->findClosed(),
            'categories' => $categories->findAllOrdered(),
        ]);
    }

    #[Route(path: '/equipment/stocktakes/start', name: 'app_equipment_stocktake_start', methods: ['POST'])]
    public function start(Request $request, EquipmentStocktakeRepository $stocktakes, EquipmentCategoryRepository $categories, EntityManagerInterface $entityManager): Response
    {
        $this->assertValidEquipmentToken('equipment_stocktake', $request);

        if (null !== $open = $stocktakes->findOpen()) {
            return $this->redirectToRoute('app_equipment_stocktake_show', ['id' => $open->getId()]);
        }

        $categoryId = (int) $request->request->getString('category');
        $category = $categoryId > 0 ? $categories->find($categoryId) : null;
        $stocktake = new EquipmentStocktake($category, $request->request->getBoolean('includesInUse'), $this->currentUser());
        $entityManager->persist($stocktake);
        $entityManager->flush();

        return $this->redirectToRoute('app_equipment_stocktake_show', ['id' => $stocktake->getId()]);
    }

    #[Route(path: '/equipment/stocktakes/{id}', name: 'app_equipment_stocktake_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, EquipmentStocktakeRepository $stocktakes, EquipmentItemRepository $items, EquipmentStocktakeLineRepository $lines, EquipmentStocktakeCloser $closer): Response
    {
        $stocktake = $stocktakes->find($id) ?? throw $this->createNotFoundException();

        if (!$stocktake->isOpen()) {
            return $this->render('equipment/stocktake_closed.html.twig', ['stocktake' => $stocktake]);
        }

        $expected = $items->findExpectedByCount($stocktake->getCategory(), EquipmentStocktakeCloser::countedStatuses($stocktake), $stocktake->getStartedAt());
        $found = $lines->foundItemIds($stocktake);

        $byType = [];
        foreach ($expected as $item) {
            $byType[(int) $item->getType()->getId()]['type'] = $item->getType();
            $byType[(int) $item->getType()->getId()]['items'][] = $item;
        }

        return $this->render('equipment/stocktake.html.twig', [
            'stocktake' => $stocktake,
            'unitGroups' => array_values($byType),
            'found' => $found,
            'foundCount' => \count(array_filter($expected, static fn (EquipmentItem $item): bool => isset($found[(int) $item->getId()]))),
            'expectedCount' => \count($expected),
            'quantityTypes' => $closer->quantityTypes($stocktake),
            'counts' => $lines->countsByType($stocktake),
        ]);
    }

    /**
     * The label code of a piece in hand, typed or read: ticks it. A code outside the count, or with
     * a wrong check digit, is said rather than guessed.
     */
    #[Route(path: '/equipment/stocktakes/{id}/scan', name: 'app_equipment_stocktake_scan', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function scan(int $id, Request $request, EquipmentStocktakeRepository $stocktakes, EquipmentItemRepository $items, EquipmentStocktakeLineRepository $lines, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        $stocktake = $this->openOrNotFound($stocktakes, $id);
        $this->assertValidEquipmentToken('equipment_stocktake', $request);

        $code = trim($request->request->getString('code'));
        $candidates = EquipmentCode::candidates($code) ?? [];
        $valid = array_values(array_filter($candidates, static fn ($candidate): bool => $candidate->isValid()));
        $found = $items->findByCodeNumbers(array_map(static fn ($candidate): int => $candidate->number, $valid));

        $expected = [];
        foreach ($items->findExpectedByCount($stocktake->getCategory(), EquipmentStocktakeCloser::countedStatuses($stocktake), $stocktake->getStartedAt()) as $item) {
            $expected[(int) $item->getId()] = $item;
        }
        $matches = array_values(array_filter($found, static fn (EquipmentItem $item): bool => isset($expected[(int) $item->getId()])));

        if (1 !== \count($matches)) {
            $this->addFlash('danger', $translator->trans(
                [] !== $candidates && [] === $valid ? 'equipmentWrongCheckDigitText' : 'equipmentStocktakeCodeUnknownMessage',
                ['%code%' => $code],
            ));

            return $this->redirectToRoute('app_equipment_stocktake_show', ['id' => $id]);
        }

        $item = $matches[0];
        if (null === $lines->findForItem($stocktake, $item)) {
            $entityManager->persist(new EquipmentStocktakeLine($stocktake, $item->getType(), $item, $this->currentUser()));
            $entityManager->flush();
        }

        $this->addFlash('success', $translator->trans('equipmentStocktakeItemFoundFlashMessage', ['%code%' => $item->getCode(), '%type%' => $item->getType()->getName()]));

        return $this->redirectToRoute('app_equipment_stocktake_show', ['id' => $id]);
    }

    /** Tick or untick one piece from the list, without typing its code. */
    #[Route(path: '/equipment/stocktakes/{id}/items/{itemId}', name: 'app_equipment_stocktake_toggle', requirements: ['id' => '\d+', 'itemId' => '\d+'], methods: ['POST'])]
    public function toggle(int $id, int $itemId, Request $request, EquipmentStocktakeRepository $stocktakes, EquipmentItemRepository $items, EquipmentStocktakeLineRepository $lines, EntityManagerInterface $entityManager): Response
    {
        $stocktake = $this->openOrNotFound($stocktakes, $id);
        $this->assertValidEquipmentToken('equipment_stocktake', $request);
        $item = $items->find($itemId) ?? throw $this->createNotFoundException();

        $line = $lines->findForItem($stocktake, $item);
        if (null !== $line) {
            $entityManager->remove($line);
        } else {
            $entityManager->persist(new EquipmentStocktakeLine($stocktake, $item->getType(), $item, $this->currentUser()));
        }
        $entityManager->flush();

        return $this->redirect($this->generateUrl('app_equipment_stocktake_show', ['id' => $id]).'#type-'.$item->getType()->getId());
    }

    /** How many of a quantity type were counted - blank means not counted, not zero. */
    #[Route(path: '/equipment/stocktakes/{id}/types/{typeId}', name: 'app_equipment_stocktake_count', requirements: ['id' => '\d+', 'typeId' => '\d+'], methods: ['POST'])]
    public function count(int $id, int $typeId, Request $request, EquipmentStocktakeRepository $stocktakes, EquipmentTypeRepository $types, EquipmentStocktakeLineRepository $lines, EntityManagerInterface $entityManager): Response
    {
        $stocktake = $this->openOrNotFound($stocktakes, $id);
        $this->assertValidEquipmentToken('equipment_stocktake', $request);
        $type = $types->find($typeId) ?? throw $this->createNotFoundException();

        $line = $lines->findCount($stocktake, $type);
        if (null === $line) {
            $line = new EquipmentStocktakeLine($stocktake, $type, null, $this->currentUser());
            $entityManager->persist($line);
        }

        $line->count(
            self::countField($request, 'available'),
            $stocktake->includesInUse() ? self::countField($request, 'inUse') : null,
            $this->currentUser(),
        );
        $entityManager->flush();
        $this->addFlash('success', 'equipmentStocktakeCountSavedFlashMessage');

        return $this->redirect($this->generateUrl('app_equipment_stocktake_show', ['id' => $id]).'#type-'.$typeId);
    }

    #[Route(path: '/equipment/stocktakes/{id}/review', name: 'app_equipment_stocktake_review', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function review(int $id, EquipmentStocktakeRepository $stocktakes, EquipmentStocktakeCloser $closer): Response
    {
        $stocktake = $this->openOrNotFound($stocktakes, $id);

        return $this->render('equipment/stocktake_review.html.twig', [
            'stocktake' => $stocktake,
            'gaps' => $closer->gaps($stocktake),
        ]);
    }

    #[Route(path: '/equipment/stocktakes/{id}/close', name: 'app_equipment_stocktake_close', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function close(int $id, Request $request, EquipmentStocktakeRepository $stocktakes, EquipmentStocktakeCloser $closer): Response
    {
        $stocktake = $this->openOrNotFound($stocktakes, $id);
        $this->assertValidEquipmentToken('equipment_stocktake', $request);

        try {
            $closer->close($stocktake, $this->currentUser());
            $this->addFlash('success', 'equipmentStocktakeClosedFlashMessage');
        } catch (EquipmentStockException $exception) {
            $this->flashRefusal($exception);
        }

        return $this->redirectToRoute('app_equipment_stocktake_show', ['id' => $id]);
    }

    /** « Abandonner » - the count goes, nothing is written. */
    #[Route(path: '/equipment/stocktakes/{id}/abandon', name: 'app_equipment_stocktake_abandon', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function abandon(int $id, Request $request, EquipmentStocktakeRepository $stocktakes, EntityManagerInterface $entityManager): Response
    {
        $stocktake = $this->openOrNotFound($stocktakes, $id);
        $this->assertValidEquipmentToken('equipment_stocktake', $request);

        $stocktake->abandon($this->currentUser());
        $entityManager->flush();
        $this->addFlash('success', 'equipmentStocktakeAbandonedFlashMessage');

        return $this->redirectToRoute('app_equipment_stocktakes');
    }

    private function openOrNotFound(EquipmentStocktakeRepository $stocktakes, int $id): EquipmentStocktake
    {
        $stocktake = $stocktakes->find($id);

        if (!$stocktake instanceof EquipmentStocktake || !$stocktake->isOpen()) {
            throw $this->createNotFoundException();
        }

        return $stocktake;
    }

    /** A blank field is « not counted », which is not zero - an empty string must not read as 0. */
    private static function countField(Request $request, string $name): ?int
    {
        $value = trim($request->request->getString($name));

        return '' === $value || !ctype_digit($value) ? null : (int) $value;
    }
}
