<?php

declare(strict_types=1);

namespace App\Controller\Equipment;

use App\Attribute\RequiresFeature;
use App\Counter\CounterRecomputer;
use App\Entity\EquipmentItem;
use App\Entity\EquipmentType;
use App\Entity\Room;
use App\Enum\Feature;
use App\Form\EquipmentMovementType;
use App\Form\EquipmentTypeType;
use App\Repository\EquipmentItemRepository;
use App\Repository\EquipmentMovementRepository;
use App\Repository\EquipmentTypeRepository;
use App\Service\Equipment\EquipmentLedger;
use App\Service\Equipment\EquipmentStockCounter;
use App\Service\Equipment\EquipmentStockException;
use App\Service\FormValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One type of Gestion > Matériel: its creation (with its first pieces or its first quantity), its
 * fiche, and the gestures recorded from the fiche.
 *
 * Creating a unit-tracked type with « Nombre d'exemplaires » at 10 creates ten pieces at once, ten
 * consecutive codes, each « Disponible » - and hands straight over to the screen that lists those
 * codes for the Dymo.
 */
#[IsGranted(new Expression(self::ACCESS_EXPRESSION))]
#[RequiresFeature(Feature::Equipment)]
class TypeController extends AbstractController
{
    use EquipmentTrait;

    #[Route(path: '/equipment/types/new', name: 'app_equipment_type_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EquipmentLedger $ledger): Response
    {
        $type = new EquipmentType();
        $form = $this->createForm(EquipmentTypeType::class, $type, ['is_creation' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $items = $ledger->createType($type, FormValue::int($form, 'quantity'), $this->currentUser());
            } catch (EquipmentStockException $exception) {
                $this->flashRefusal($exception);

                return $this->render('equipment/type_form.html.twig', ['form' => $form, 'type' => null]);
            }

            $this->addFlash('success', 'equipmentTypeCreatedFlashMessage');

            return $this->redirectAfterIntake($type, $items);
        }

        return $this->render('equipment/type_form.html.twig', ['form' => $form, 'type' => null]);
    }

    #[Route(path: '/equipment/types/{id}', name: 'app_equipment_type_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, EquipmentTypeRepository $types, EquipmentItemRepository $items, EquipmentMovementRepository $movements): Response
    {
        $type = $this->findTypeOrNotFound($types, $id);

        return $this->render('equipment/type_show.html.twig', [
            'type' => $type,
            'items' => $type->isUnitTracked() ? $items->findForType($type) : [],
            'movements' => $movements->findRecentForType($type),
            'addStockForm' => $this->addStockForm($type),
            'deployForm' => $type->isUnitTracked() ? null : $this->quantityForm($type, 'deploy'),
            'returnForm' => $type->isUnitTracked() ? null : $this->quantityForm($type, 'return'),
        ]);
    }

    #[Route(path: '/equipment/types/{id}/edit', name: 'app_equipment_type_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, EquipmentTypeRepository $types, EntityManagerInterface $entityManager): Response
    {
        $type = $this->findTypeOrNotFound($types, $id);
        $form = $this->createForm(EquipmentTypeType::class, $type);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'equipmentTypeUpdatedFlashMessage');

            return $this->redirectToRoute('app_equipment_type_show', ['id' => $type->getId()]);
        }

        return $this->render('equipment/type_form.html.twig', ['form' => $form, 'type' => $type]);
    }

    /** « Ajouter des exemplaires » / « Ajouter au stock » - the next delivery of an existing type. */
    #[Route(path: '/equipment/types/{id}/stock', name: 'app_equipment_type_add_stock', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addStock(int $id, Request $request, EquipmentTypeRepository $types, EquipmentLedger $ledger): Response
    {
        $type = $this->findTypeOrNotFound($types, $id);
        $form = $this->addStockForm($type);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->flashFormErrors($form);

            return $this->redirectToRoute('app_equipment_type_show', ['id' => $id]);
        }

        try {
            $items = $ledger->addStock($type, FormValue::int($form, 'quantity'), $this->currentUser(), FormValue::trimmed($form, 'note'));
        } catch (EquipmentStockException $exception) {
            $this->flashRefusal($exception);

            return $this->redirectToRoute('app_equipment_type_show', ['id' => $id]);
        }

        $this->addFlash('success', 'equipmentStockAddedFlashMessage');

        return $this->redirectAfterIntake($type, $items);
    }

    /** « Utilisé » / « Disponible » for a quantity - a number of cables leaves the reserve or comes back. */
    #[Route(path: '/equipment/types/{id}/{direction}', name: 'app_equipment_type_move', requirements: ['id' => '\d+', 'direction' => 'deploy|return'], methods: ['POST'])]
    public function move(int $id, string $direction, Request $request, EquipmentTypeRepository $types, EquipmentLedger $ledger): Response
    {
        $type = $this->findTypeOrNotFound($types, $id);

        if ($type->isUnitTracked()) {
            throw $this->createNotFoundException();
        }

        $form = $this->quantityForm($type, $direction);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->flashFormErrors($form);

            return $this->redirectToRoute('app_equipment_type_show', ['id' => $id]);
        }

        $quantity = FormValue::int($form, 'quantity');

        try {
            if ('deploy' === $direction) {
                $room = $form->get('room')->getData();
                $ledger->deployQuantity($type, $quantity, $room instanceof Room ? $room : null, $this->currentUser());
            } else {
                $ledger->returnQuantity($type, $quantity, $this->currentUser());
            }
        } catch (EquipmentStockException $exception) {
            $this->flashRefusal($exception);

            return $this->redirectToRoute('app_equipment_type_show', ['id' => $id]);
        }

        $this->addFlash('success', 'deploy' === $direction ? 'equipmentDeployedFlashMessage' : 'equipmentReturnedFlashMessage');

        return $this->redirectToRoute('app_equipment_type_show', ['id' => $id]);
    }

    /** « Recalculer ce compteur » - this type's counters against its journal. */
    #[Route(path: '/equipment/types/{id}/recompute', name: 'app_equipment_type_recompute', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function recompute(int $id, Request $request, EquipmentTypeRepository $types, CounterRecomputer $recomputer, TranslatorInterface $translator): Response
    {
        $type = $this->findTypeOrNotFound($types, $id);
        $this->assertValidEquipmentToken('equipment_recompute', $request);

        $run = $recomputer->recompute(EquipmentStockCounter::NAME, (int) $type->getId());

        if ([] === $run->drifts) {
            $this->addFlash('success', 'equipmentTypeCounterCheckedFlashMessage');
        } else {
            $this->addFlash('warning', $translator->trans('equipmentTypeCounterCorrectedFlashMessage', ['%detail%' => $run->drifts[0]->describe()]));
        }

        return $this->redirectToRoute('app_equipment_type_show', ['id' => $id]);
    }

    /**
     * After a delivery of pieces, the codes to type on the Dymo; after a quantity, the fiche.
     *
     * @param list<EquipmentItem> $items
     */
    private function redirectAfterIntake(EquipmentType $type, array $items): Response
    {
        if ([] === $items) {
            return $this->redirectToRoute('app_equipment_type_show', ['id' => $type->getId()]);
        }

        return $this->redirectToRoute('app_equipment_labels', [
            'from' => $items[0]->getCodeNumber(),
            'to' => $items[\count($items) - 1]->getCodeNumber(),
        ]);
    }

    private function addStockForm(EquipmentType $type): FormInterface
    {
        return $this->createForm(EquipmentMovementType::class, null, [
            'action' => $this->generateUrl('app_equipment_type_add_stock', ['id' => $type->getId()]),
            'max_quantity' => $type->isUnitTracked() ? EquipmentLedger::MAX_UNITS_PER_BATCH : null,
            'with_note' => true,
            'submit_label' => $type->isUnitTracked() ? 'equipmentAddUnitsAction' : 'equipmentAddQuantityAction',
        ]);
    }

    private function quantityForm(EquipmentType $type, string $direction): FormInterface
    {
        return $this->container->get('form.factory')->createNamed('equipment_'.$direction, EquipmentMovementType::class, null, [
            'action' => $this->generateUrl('app_equipment_type_move', ['id' => $type->getId(), 'direction' => $direction]),
            'with_room' => 'deploy' === $direction,
            'submit_label' => 'deploy' === $direction ? 'equipmentDeployQuantityAction' : 'equipmentReturnQuantityAction',
        ]);
    }

    private function flashFormErrors(FormInterface $form): void
    {
        foreach ($form->getErrors(true) as $error) {
            $this->addFlash('danger', $error->getMessage());
        }
    }

    private function findTypeOrNotFound(EquipmentTypeRepository $types, int $id): EquipmentType
    {
        return $types->find($id) ?? throw $this->createNotFoundException();
    }
}
