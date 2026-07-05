<?php

namespace App\Controller;

use App\Entity\Cohort;
use App\Entity\Section;
use App\Entity\Track;
use App\Form\CohortType;
use App\Form\SectionType;
use App\Form\TrackType;
use App\Repository\CohortRepository;
use App\Repository\SectionRepository;
use App\Repository\TrackRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class SettingsStructureController extends AbstractController
{
    #[Route(path: '/parametres/structure', name: 'app_settings_structure')]
    public function index(): Response
    {
        return $this->render('settings/structure.html.twig');
    }

    #[Route(path: '/parametres/structure/sections/nouveau', name: 'app_settings_structure_sections_new')]
    public function newSection(Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(SectionType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($form->getData());
            $entityManager->flush();

            $this->addFlash('success', 'sectionCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure');
        }

        return $this->render('settings/section_new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(path: '/parametres/structure/filieres/nouveau', name: 'app_settings_structure_tracks_new')]
    public function newTrack(Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(TrackType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($form->getData());
            $entityManager->flush();

            $this->addFlash('success', 'trackCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure');
        }

        return $this->render('settings/track_new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(path: '/parametres/structure/classes/nouveau', name: 'app_settings_structure_cohorts_new')]
    public function newCohort(Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(CohortType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($form->getData());
            $entityManager->flush();

            $this->addFlash('success', 'cohortCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure');
        }

        return $this->render('settings/cohort_new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(path: '/parametres/structure/sections/data', name: 'app_settings_structure_sections_data')]
    public function sectionsData(Request $request, SectionRepository $repository): JsonResponse
    {
        [$draw, $start, $length, $search] = $this->readDataTableParams($request);

        $total = $repository->countAll();
        $filteredTotal = '' !== $search ? $repository->countAll($search) : $total;
        $rows = $repository->findPageOrderedByMostRecent($start, $length, '' !== $search ? $search : null);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (Section $section): array => [
                    'name' => $section->getName(),
                    'slug' => $section->getSlug(),
                    'ldapGroupName' => $section->getLdapGroup()?->getName() ?? '—',
                    'creationDate' => $section->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $section->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }

    #[Route(path: '/parametres/structure/filieres/data', name: 'app_settings_structure_tracks_data')]
    public function tracksData(Request $request, TrackRepository $repository): JsonResponse
    {
        [$draw, $start, $length, $search] = $this->readDataTableParams($request);

        $total = $repository->countAll();
        $filteredTotal = '' !== $search ? $repository->countAll($search) : $total;
        $rows = $repository->findPageOrderedByMostRecent($start, $length, '' !== $search ? $search : null);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (Track $track): array => [
                    'name' => $track->getName(),
                    'slug' => $track->getSlug(),
                    'sectionName' => $track->getSection()->getName(),
                    'ldapGroupName' => $track->getLdapGroup()?->getName() ?? '—',
                    'creationDate' => $track->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $track->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }

    #[Route(path: '/parametres/structure/classes/data', name: 'app_settings_structure_cohorts_data')]
    public function cohortsData(Request $request, CohortRepository $repository): JsonResponse
    {
        [$draw, $start, $length, $search] = $this->readDataTableParams($request);

        $total = $repository->countAll();
        $filteredTotal = '' !== $search ? $repository->countAll($search) : $total;
        $rows = $repository->findPageOrderedByMostRecent($start, $length, '' !== $search ? $search : null);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (Cohort $cohort): array => [
                    'name' => $cohort->getName(),
                    'slug' => $cohort->getSlug(),
                    'trackName' => $cohort->getTrack()->getName(),
                    'ldapGroupName' => $cohort->getLdapGroup()?->getName() ?? '—',
                    'creationDate' => $cohort->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $cohort->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }

    /** @return array{0: int, 1: int, 2: int, 3: string} */
    private function readDataTableParams(Request $request): array
    {
        $draw = $request->query->getInt('draw', 1);
        $start = max(0, $request->query->getInt('start', 0));
        $length = $request->query->getInt('length', 10);
        $length = $length > 0 ? min($length, 50) : 10;
        $search = trim((string) ($request->query->all('search')['value'] ?? ''));

        return [$draw, $start, $length, $search];
    }
}
