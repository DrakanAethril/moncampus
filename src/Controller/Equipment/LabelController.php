<?php

declare(strict_types=1);

namespace App\Controller\Equipment;

use App\Attribute\RequiresFeature;
use App\Enum\Feature;
use App\Repository\EquipmentItemRepository;
use App\Service\QueryValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion > Matériel > À étiqueter - the codes to type on the Dymo.
 *
 * The labeller is a keyboard Dymo with no link to a computer, so nothing is printed from here: the
 * codes are shown large, in the order they are typed, and each piece leaves the list when somebody
 * ticks « Étiquette posée ». Right after a creation or a delivery the screen opens on that batch
 * (`?from=&to=`); otherwise it lists every piece nobody has labelled yet, so a batch left for the
 * next morning is not lost.
 */
#[IsGranted(new Expression(self::ACCESS_EXPRESSION))]
#[RequiresFeature(Feature::Equipment)]
class LabelController extends AbstractController
{
    use EquipmentTrait;

    #[Route(path: '/equipment/labels', name: 'app_equipment_labels', methods: ['GET'])]
    public function index(Request $request, EquipmentItemRepository $items): Response
    {
        $from = QueryValue::nullableInt($request, 'from');
        $to = QueryValue::nullableInt($request, 'to');
        $isBatch = null !== $from && null !== $to && $from <= $to;

        return $this->render('equipment/labels.html.twig', [
            'items' => $isBatch ? $items->findByCodeRange($from, $to) : $items->findUnlabeled(),
            'isBatch' => $isBatch,
            'unlabeledCount' => $items->countUnlabeled(),
        ]);
    }

    /** « Tout marquer comme étiqueté » - the ticked pieces, or the whole batch shown. */
    #[Route(path: '/equipment/labels/mark', name: 'app_equipment_labels_mark', methods: ['POST'])]
    public function mark(Request $request, EquipmentItemRepository $items, EntityManagerInterface $entityManager): Response
    {
        $this->assertValidEquipmentToken('equipment_labels', $request);

        $ids = array_values(array_filter(
            array_map('intval', $request->request->all('items')),
            static fn (int $id): bool => $id > 0,
        ));

        $now = new \DateTimeImmutable();
        $marked = 0;
        foreach ([] === $ids ? [] : $items->findBy(['id' => $ids]) as $item) {
            if (!$item->isLabeled()) {
                $item->setLabeledAt($now);
                ++$marked;
            }
        }
        $entityManager->flush();

        if ($marked > 0) {
            $this->addFlash('success', 'equipmentLabelsMarkedFlashMessage');
        }

        return $this->redirectToRoute('app_equipment_labels');
    }
}
