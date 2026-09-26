<?php

declare(strict_types=1);

namespace App\Controller\Equipment;

use App\Attribute\RequiresFeature;
use App\Counter\CounterRecomputer;
use App\Entity\EquipmentItem;
use App\Enum\Feature;
use App\Repository\EquipmentCategoryRepository;
use App\Repository\EquipmentItemRepository;
use App\Repository\EquipmentTypeRepository;
use App\Service\Equipment\EquipmentCode;
use App\Service\Equipment\EquipmentStockCounter;
use App\Service\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Gestion > Matériel > Stock - every type with what is available and what is in use, and the search
 * that reads a label code back.
 *
 * The screen reads the counters stored on each type and nothing else: no journal is summed to draw
 * it, which is the whole point of storing them. The button « Recalculer les compteurs » checks the
 * equipment counters alone - the platform-wide pass is the command's.
 */
#[IsGranted(new Expression(self::ACCESS_EXPRESSION))]
#[RequiresFeature(Feature::Equipment)]
class StockController extends AbstractController
{
    use EquipmentTrait;

    #[Route(path: '/equipment', name: 'app_equipment', methods: ['GET'])]
    public function index(Request $request, EquipmentTypeRepository $types, EquipmentCategoryRepository $categories, EquipmentItemRepository $items): Response
    {
        $search = QueryValue::trimmed($request, 'q');

        // Something that reads as a label code is a lookup, not a filter on names.
        if ('' !== $search && null !== EquipmentCode::candidates($search)) {
            return $this->redirectToRoute('app_equipment_search', ['q' => $search]);
        }

        $categoryId = QueryValue::nullableInt($request, 'category');
        $category = null !== $categoryId ? $categories->find($categoryId) : null;
        $alertsOnly = QueryValue::bool($request, 'alerts');

        return $this->render('equipment/index.html.twig', [
            'types' => $types->findForStock($category, $search, $alertsOnly),
            'categories' => $categories->findAllOrdered(),
            'category' => $category,
            'search' => $search,
            'alertsOnly' => $alertsOnly,
            'unlabeledCount' => $items->countUnlabeled(),
        ]);
    }

    /**
     * The label code, read back however it was typed. One valid match opens the piece at once;
     * anything else - several readings, a wrong check digit, nothing - is shown and explained. A
     * wrong check digit is never resolved to the piece it nearly names: the screen says the label
     * or the typing is wrong, and shows that piece as a suggestion only.
     */
    #[Route(path: '/equipment/search', name: 'app_equipment_search', methods: ['GET'])]
    public function search(Request $request, EquipmentItemRepository $items): Response
    {
        $query = QueryValue::trimmed($request, 'q');
        $candidates = EquipmentCode::candidates($query);

        if (null === $candidates) {
            return $this->redirectToRoute('app_equipment', ['q' => $query]);
        }

        $found = $items->findByCodeNumbers(array_map(static fn ($candidate): int => $candidate->number, $candidates));

        $matches = [];
        $mistyped = [];
        foreach ($candidates as $candidate) {
            $item = $found[$candidate->number] ?? null;
            if (!$item instanceof EquipmentItem) {
                continue;
            }

            if ($candidate->isValid()) {
                $matches[$item->getCodeNumber()] = $item;
            } else {
                $mistyped[$item->getCodeNumber()] = $item;
            }
        }

        if (1 === \count($matches) && [] === $mistyped) {
            return $this->redirectToRoute('app_equipment_item_show', ['id' => array_values($matches)[0]->getId()]);
        }

        $hasWrongCheckDigit = [] !== array_filter($candidates, static fn ($candidate): bool => !$candidate->isValid());

        return $this->render('equipment/search.html.twig', [
            'query' => $query,
            'matches' => array_values($matches),
            'mistyped' => array_values($mistyped),
            'hasWrongCheckDigit' => $hasWrongCheckDigit,
        ]);
    }

    #[Route(path: '/equipment/counters/recompute', name: 'app_equipment_recompute', methods: ['POST'])]
    public function recompute(Request $request, CounterRecomputer $recomputer, TranslatorInterface $translator): Response
    {
        $this->assertValidEquipmentToken('equipment_recompute', $request);

        $run = $recomputer->recompute(EquipmentStockCounter::NAME);

        $this->addFlash([] === $run->drifts ? 'success' : 'warning', [] === $run->drifts
            ? $translator->trans('equipmentCountersCheckedFlashMessage', ['%count%' => $run->checked])
            : $translator->trans('equipmentCountersCorrectedFlashMessage', ['%count%' => $run->checked, '%drifts%' => \count($run->drifts)]));

        return $this->redirectToRoute('app_equipment');
    }
}
