<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Entity\Room;
use App\Form\RoomType;
use App\Repository\RoomRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Paramètres > Configuration, onglet « Salles ».
 *
 * Split out of the former SettingsStructureController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class RoomController extends AbstractController
{
    use SettingsTabTrait;

    #[Route(path: '/settings/structure/rooms', name: 'app_settings_structure_rooms')]
    public function roomsTab(): Response
    {
        return $this->renderTab('rooms');
    }

    #[Route(path: '/settings/structure/rooms/new', name: 'app_settings_structure_rooms_new')]
    #[Route(path: '/settings/structure/rooms/{id}/edit', name: 'app_settings_structure_rooms_edit')]
    public function roomForm(Request $request, EntityManagerInterface $entityManager, RoomRepository $repository, ?int $id = null): Response
    {
        $room = null !== $id ? $this->findOrNotFound($repository, $id) : null;
        $isEdit = null !== $room;

        $form = $this->createForm(RoomType::class, $room);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'roomUpdatedFlashMessage' : 'roomCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_rooms');
        }

        return $this->render('settings/room_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/settings/structure/rooms/{id}/deactivate', name: 'app_settings_structure_rooms_deactivate', methods: ['POST'])]
    public function deactivateRoom(Request $request, EntityManagerInterface $entityManager, RoomRepository $repository, int $id): JsonResponse
    {
        $room = $this->findOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $room->setInactiveDate(new \DateTimeImmutable());
        $room->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/settings/structure/rooms/data', name: 'app_settings_structure_rooms_data')]
    public function roomsData(Request $request, RoomRepository $repository): JsonResponse
    {
        [$draw, $start, $length, $search, $includeInactive] = $this->readDataTableParams($request);

        $total = $repository->countAll(null, $includeInactive);
        $filteredTotal = '' !== $search ? $repository->countAll($search, $includeInactive) : $total;
        $rows = $repository->findPageOrderedByMostRecent($start, $length, '' !== $search ? $search : null, $includeInactive);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (Room $room): array => [
                    'id' => $room->getId(),
                    'isInactive' => null !== $room->getInactiveDate(),
                    'name' => $room->getName(),
                    'creationDate' => $room->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $room->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($room->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($room->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($room->getLastUpdatedBy()),
                    'lastUpdatedDate' => $room->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }
}
