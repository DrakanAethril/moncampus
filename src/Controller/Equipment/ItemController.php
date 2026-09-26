<?php

declare(strict_types=1);

namespace App\Controller\Equipment;

use App\Attribute\RequiresFeature;
use App\Entity\EquipmentItem;
use App\Entity\Room;
use App\Enum\EquipmentItemStatus;
use App\Enum\Feature;
use App\Form\EquipmentItemType;
use App\Form\EquipmentMovementType;
use App\Repository\EquipmentItemRepository;
use App\Repository\EquipmentMovementRepository;
use App\Service\Equipment\EquipmentLedger;
use App\Service\Equipment\EquipmentStockException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One labelled piece: its fiche, « Utilisé » / « Disponible », and the details that are not its
 * history (serial number, storage place, notes, label stuck on).
 */
#[IsGranted(new Expression(self::ACCESS_EXPRESSION))]
#[RequiresFeature(Feature::Equipment)]
class ItemController extends AbstractController
{
    use EquipmentTrait;

    #[Route(path: '/equipment/items/{id}', name: 'app_equipment_item_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, EquipmentItemRepository $items, EquipmentMovementRepository $movements): Response
    {
        $item = $this->findItemOrNotFound($items, $id);

        return $this->render('equipment/item_show.html.twig', [
            'item' => $item,
            'movements' => $movements->findForItem($item),
            'deployForm' => EquipmentItemStatus::Available === $item->getStatus() ? $this->deployForm($item) : null,
        ]);
    }

    #[Route(path: '/equipment/items/{id}/edit', name: 'app_equipment_item_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, EquipmentItemRepository $items, EntityManagerInterface $entityManager): Response
    {
        $item = $this->findItemOrNotFound($items, $id);
        $form = $this->createForm(EquipmentItemType::class, $item);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'equipmentItemUpdatedFlashMessage');

            return $this->redirectToRoute('app_equipment_item_show', ['id' => $id]);
        }

        return $this->render('equipment/item_edit.html.twig', ['item' => $item, 'form' => $form]);
    }

    /** « Utilisé », with the room when somebody names one. */
    #[Route(path: '/equipment/items/{id}/deploy', name: 'app_equipment_item_deploy', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deploy(int $id, Request $request, EquipmentItemRepository $items, EquipmentLedger $ledger): Response
    {
        $item = $this->findItemOrNotFound($items, $id);

        // Two doors: the fiche's form, which offers the room, and the one-click button of a list,
        // which names none and carries a plain token like « Disponible » does.
        if ($request->request->has('_token')) {
            $this->assertValidEquipmentToken('equipment_item_move', $request);
            $room = null;
        } else {
            $form = $this->deployForm($item);
            $form->handleRequest($request);

            if (!$form->isSubmitted() || !$form->isValid()) {
                return $this->redirectBack($request, $item);
            }

            $room = $form->get('room')->getData();
        }

        try {
            $ledger->deployItem($item, $room instanceof Room ? $room : null, $this->currentUser());
            $this->addFlash('success', 'equipmentItemDeployedFlashMessage');
        } catch (EquipmentStockException $exception) {
            $this->flashRefusal($exception);
        }

        return $this->redirectBack($request, $item);
    }

    /** « Disponible » - back into the reserve. */
    #[Route(path: '/equipment/items/{id}/return', name: 'app_equipment_item_return', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function return(int $id, Request $request, EquipmentItemRepository $items, EquipmentLedger $ledger): Response
    {
        $item = $this->findItemOrNotFound($items, $id);
        $this->assertValidEquipmentToken('equipment_item_move', $request);

        try {
            $ledger->returnItem($item, $this->currentUser());
            $this->addFlash('success', 'equipmentItemReturnedFlashMessage');
        } catch (EquipmentStockException $exception) {
            $this->flashRefusal($exception);
        }

        return $this->redirectBack($request, $item);
    }

    /**
     * « Étiquette posée », or its undoing. Not a journal line: sticking a label on moves nothing,
     * it only takes the piece off the « À étiqueter » list.
     */
    #[Route(path: '/equipment/items/{id}/labeled', name: 'app_equipment_item_labeled', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function labeled(int $id, Request $request, EquipmentItemRepository $items, EntityManagerInterface $entityManager): Response
    {
        $item = $this->findItemOrNotFound($items, $id);
        $this->assertValidEquipmentToken('equipment_labels', $request);

        $item->setLabeledAt($item->isLabeled() ? null : new \DateTimeImmutable());
        $entityManager->flush();

        return $this->redirectBack($request, $item);
    }

    private function deployForm(EquipmentItem $item): FormInterface
    {
        return $this->createForm(EquipmentMovementType::class, null, [
            'action' => $this->generateUrl('app_equipment_item_deploy', ['id' => $item->getId()]),
            'with_quantity' => false,
            'with_room' => true,
            'submit_label' => 'equipmentMarkInUseAction',
        ]);
    }

    /**
     * The gestures are offered on the type's list of pieces as well as on the piece's own fiche;
     * `_back=type` sends the user back where they clicked.
     */
    private function redirectBack(Request $request, EquipmentItem $item): Response
    {
        return match ($request->request->getString('_back')) {
            'type' => $this->redirectToRoute('app_equipment_type_show', ['id' => $item->getType()->getId()]),
            'labels' => $this->redirectToRoute('app_equipment_labels'),
            default => $this->redirectToRoute('app_equipment_item_show', ['id' => $item->getId()]),
        };
    }

    private function findItemOrNotFound(EquipmentItemRepository $items, int $id): EquipmentItem
    {
        return $items->find($id) ?? throw $this->createNotFoundException();
    }
}
