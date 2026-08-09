<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Entity\Track;
use App\Form\TrackType;
use App\Repository\TrackRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Paramètres > Configuration, onglet « Filières ».
 *
 * Split out of the former SettingsStructureController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class TrackController extends AbstractController
{
    use SettingsTabTrait;

    #[Route(path: '/settings/structure/tracks', name: 'app_settings_structure_tracks')]
    public function tracksTab(): Response
    {
        return $this->renderTab('tracks');
    }

    #[Route(path: '/settings/structure/tracks/new', name: 'app_settings_structure_tracks_new')]
    #[Route(path: '/settings/structure/tracks/{id}/edit', name: 'app_settings_structure_tracks_edit')]
    public function trackForm(Request $request, EntityManagerInterface $entityManager, TrackRepository $repository, ?int $id = null): Response
    {
        $track = null !== $id ? $this->findOrNotFound($repository, $id) : null;
        $isEdit = null !== $track;

        $form = $this->createForm(TrackType::class, $track);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'trackUpdatedFlashMessage' : 'trackCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_tracks');
        }

        return $this->render('settings/track_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/settings/structure/tracks/{id}/deactivate', name: 'app_settings_structure_tracks_deactivate', methods: ['POST'])]
    public function deactivateTrack(Request $request, EntityManagerInterface $entityManager, TrackRepository $repository, int $id): JsonResponse
    {
        $track = $this->findOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $track->setInactiveDate(new \DateTimeImmutable());
        $track->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/settings/structure/tracks/data', name: 'app_settings_structure_tracks_data')]
    public function tracksData(Request $request, TrackRepository $repository): JsonResponse
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
                fn (Track $track): array => [
                    'id' => $track->getId(),
                    'isInactive' => null !== $track->getInactiveDate(),
                    'name' => $track->getName(),
                    'slug' => $track->getSlug(),
                    'sectionName' => $track->getSection()->getName(),
                    'ldapGroupName' => $track->getLdapGroup()?->getName() ?? '—',
                    'creationDate' => $track->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $track->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($track->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($track->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($track->getLastUpdatedBy()),
                    'lastUpdatedDate' => $track->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }
}
