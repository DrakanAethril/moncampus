<?php

namespace App\Controller;

use App\Entity\Cohort;
use App\Entity\Modality;
use App\Entity\Option;
use App\Entity\Section;
use App\Entity\Track;
use App\Form\CohortType;
use App\Form\ModalityType;
use App\Form\OptionType;
use App\Form\SectionType;
use App\Form\TrackType;
use App\Repository\CohortRepository;
use App\Repository\ModalityRepository;
use App\Repository\OptionRepository;
use App\Repository\SectionRepository;
use App\Repository\TrackRepository;
use Doctrine\Common\Collections\Collection;
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
    #[Route(path: '/settings/structure', name: 'app_settings_structure')]
    public function index(): Response
    {
        return $this->render('settings/structure.html.twig');
    }

    #[Route(path: '/settings/structure/sections/new', name: 'app_settings_structure_sections_new')]
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

    #[Route(path: '/settings/structure/tracks/new', name: 'app_settings_structure_tracks_new')]
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

    #[Route(path: '/settings/structure/cohorts/new', name: 'app_settings_structure_cohorts_new')]
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

    #[Route(path: '/settings/structure/options/new', name: 'app_settings_structure_options_new')]
    public function newOption(Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(OptionType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($form->getData());
            $entityManager->flush();

            $this->addFlash('success', 'optionCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure');
        }

        return $this->render('settings/option_new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(path: '/settings/structure/modalities/new', name: 'app_settings_structure_modalities_new')]
    public function newModality(Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(ModalityType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($form->getData());
            $entityManager->flush();

            $this->addFlash('success', 'modalityCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure');
        }

        return $this->render('settings/modality_new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(path: '/settings/structure/sections/data', name: 'app_settings_structure_sections_data')]
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

    #[Route(path: '/settings/structure/tracks/data', name: 'app_settings_structure_tracks_data')]
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

    #[Route(path: '/settings/structure/cohorts/data', name: 'app_settings_structure_cohorts_data')]
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

    #[Route(path: '/settings/structure/options/data', name: 'app_settings_structure_options_data')]
    public function optionsData(Request $request, OptionRepository $repository): JsonResponse
    {
        [$draw, $start, $length, $search] = $this->readDataTableParams($request);

        $total = $repository->countAll();
        $filteredTotal = '' !== $search ? $repository->countAll($search) : $total;
        $rows = $repository->findPageOrderedByMostRecent($start, $length, '' !== $search ? $search : null);
        $repository->hydrateCohorts($rows);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (Option $option): array => [
                    'name' => $option->getName(),
                    'shortName' => $option->getShortName(),
                    'slug' => $option->getSlug(),
                    'cohortNames' => $this->cohortNames($option->getCohorts()),
                    'ldapGroupName' => $option->getLdapGroup()?->getName() ?? '—',
                    'creationDate' => $option->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $option->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }

    #[Route(path: '/settings/structure/modalities/data', name: 'app_settings_structure_modalities_data')]
    public function modalitiesData(Request $request, ModalityRepository $repository): JsonResponse
    {
        [$draw, $start, $length, $search] = $this->readDataTableParams($request);

        $total = $repository->countAll();
        $filteredTotal = '' !== $search ? $repository->countAll($search) : $total;
        $rows = $repository->findPageOrderedByMostRecent($start, $length, '' !== $search ? $search : null);
        $repository->hydrateCohorts($rows);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (Modality $modality): array => [
                    'name' => $modality->getName(),
                    'slug' => $modality->getSlug(),
                    'cohortNames' => $this->cohortNames($modality->getCohorts()),
                    'ldapGroupName' => $modality->getLdapGroup()?->getName() ?? '—',
                    'creationDate' => $modality->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $modality->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
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

    /** @param Collection<int, Cohort> $cohorts */
    private function cohortNames(Collection $cohorts): string
    {
        return implode(', ', array_map(fn (Cohort $cohort): string => $cohort->getName(), $cohorts->toArray()));
    }
}
