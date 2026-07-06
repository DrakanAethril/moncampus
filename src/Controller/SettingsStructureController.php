<?php

namespace App\Controller;

use App\Entity\Cohort;
use App\Entity\Modality;
use App\Entity\Option;
use App\Entity\Period;
use App\Entity\Program;
use App\Entity\Room;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Track;
use App\Entity\User;
use App\Form\CohortType;
use App\Form\ModalityType;
use App\Form\OptionType;
use App\Form\PeriodType;
use App\Form\ProgramType;
use App\Form\RoomType;
use App\Form\SchoolYearType;
use App\Form\SectionType;
use App\Form\TrackType;
use App\Repository\CohortRepository;
use App\Repository\ModalityRepository;
use App\Repository\OptionRepository;
use App\Repository\PeriodRepository;
use App\Repository\ProgramRepository;
use App\Repository\RoomRepository;
use App\Repository\SchoolYearRepository;
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
    // Each tab has its own route so navigating between tabs only loads that tab's content
    // (and fires only that tab's DataTables request) instead of rendering all 7 tabs' tables
    // up front. All of them render the same settings/structure.html.twig shell, which then
    // includes just the requested tab's button/content partials based on activeTab.
    #[Route(path: '/settings/structure', name: 'app_settings_structure')]
    #[Route(path: '/settings/structure/sections', name: 'app_settings_structure_sections')]
    public function sectionsTab(): Response
    {
        return $this->renderTab('sections');
    }

    #[Route(path: '/settings/structure/tracks', name: 'app_settings_structure_tracks')]
    public function tracksTab(): Response
    {
        return $this->renderTab('tracks');
    }

    #[Route(path: '/settings/structure/cohorts', name: 'app_settings_structure_cohorts')]
    public function cohortsTab(): Response
    {
        return $this->renderTab('cohorts');
    }

    #[Route(path: '/settings/structure/rooms', name: 'app_settings_structure_rooms')]
    public function roomsTab(): Response
    {
        return $this->renderTab('rooms');
    }

    #[Route(path: '/settings/structure/options', name: 'app_settings_structure_options')]
    public function optionsTab(): Response
    {
        return $this->renderTab('options');
    }

    #[Route(path: '/settings/structure/modalities', name: 'app_settings_structure_modalities')]
    public function modalitiesTab(): Response
    {
        return $this->renderTab('modalities');
    }

    #[Route(path: '/settings/structure/school-years', name: 'app_settings_structure_school_years')]
    public function schoolYearsTab(): Response
    {
        return $this->renderTab('school_years');
    }

    #[Route(path: '/settings/structure/programs', name: 'app_settings_structure_programs')]
    public function programsTab(): Response
    {
        return $this->renderTab('programs');
    }

    #[Route(path: '/settings/structure/periods', name: 'app_settings_structure_periods')]
    public function periodsTab(): Response
    {
        return $this->renderTab('periods');
    }

    private function renderTab(string $tab): Response
    {
        return $this->render('settings/structure.html.twig', [
            'activeTab' => $tab,
        ]);
    }

    #[Route(path: '/settings/structure/sections/new', name: 'app_settings_structure_sections_new')]
    public function newSection(Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(SectionType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $entity->setCreatedBy($this->currentUser());

            $entityManager->persist($entity);
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
            $entity = $form->getData();
            $entity->setCreatedBy($this->currentUser());

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', 'trackCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_tracks');
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
            $entity = $form->getData();
            $entity->setCreatedBy($this->currentUser());

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', 'cohortCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_cohorts');
        }

        return $this->render('settings/cohort_new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(path: '/settings/structure/rooms/new', name: 'app_settings_structure_rooms_new')]
    public function newRoom(Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(RoomType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $entity->setCreatedBy($this->currentUser());

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', 'roomCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_rooms');
        }

        return $this->render('settings/room_new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(path: '/settings/structure/options/new', name: 'app_settings_structure_options_new')]
    public function newOption(Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(OptionType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $entity->setCreatedBy($this->currentUser());

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', 'optionCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_options');
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
            $entity = $form->getData();
            $entity->setCreatedBy($this->currentUser());

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', 'modalityCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_modalities');
        }

        return $this->render('settings/modality_new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(path: '/settings/structure/school-years/new', name: 'app_settings_structure_school_years_new')]
    public function newSchoolYear(Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(SchoolYearType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $entity->setCreatedBy($this->currentUser());

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', 'schoolYearCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_school_years');
        }

        return $this->render('settings/school_year_new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(path: '/settings/structure/programs/new', name: 'app_settings_structure_programs_new')]
    public function newProgram(Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(ProgramType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $entity->setCreatedBy($this->currentUser());

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', 'programCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_programs');
        }

        return $this->render('settings/program_new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(path: '/settings/structure/periods/new', name: 'app_settings_structure_periods_new')]
    public function newPeriod(Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(PeriodType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $entity->setCreatedBy($this->currentUser());

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', 'periodCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_periods');
        }

        return $this->render('settings/period_new.html.twig', [
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
                    'createdByName' => $this->userLabel($section->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($section->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($section->getLastUpdatedBy()),
                    'lastUpdatedDate' => $section->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
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
                    'createdByName' => $this->userLabel($track->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($track->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($track->getLastUpdatedBy()),
                    'lastUpdatedDate' => $track->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
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
                    'createdByName' => $this->userLabel($cohort->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($cohort->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($cohort->getLastUpdatedBy()),
                    'lastUpdatedDate' => $cohort->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }

    #[Route(path: '/settings/structure/rooms/data', name: 'app_settings_structure_rooms_data')]
    public function roomsData(Request $request, RoomRepository $repository): JsonResponse
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
                fn (Room $room): array => [
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

    #[Route(path: '/settings/structure/options/data', name: 'app_settings_structure_options_data')]
    public function optionsData(Request $request, OptionRepository $repository): JsonResponse
    {
        [$draw, $start, $length, $search] = $this->readDataTableParams($request);

        $total = $repository->countAll();
        $filteredTotal = '' !== $search ? $repository->countAll($search) : $total;
        $rows = $repository->findPageOrderedByMostRecent($start, $length, '' !== $search ? $search : null);
        $repository->hydratePrograms($rows);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (Option $option): array => [
                    'name' => $option->getName(),
                    'shortName' => $option->getShortName(),
                    'slug' => $option->getSlug(),
                    'programNames' => $this->programNames($option->getPrograms()),
                    'ldapGroupName' => $option->getLdapGroup()?->getName() ?? '—',
                    'creationDate' => $option->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $option->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($option->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($option->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($option->getLastUpdatedBy()),
                    'lastUpdatedDate' => $option->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
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
        $repository->hydratePrograms($rows);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (Modality $modality): array => [
                    'name' => $modality->getName(),
                    'slug' => $modality->getSlug(),
                    'programNames' => $this->programNames($modality->getPrograms()),
                    'ldapGroupName' => $modality->getLdapGroup()?->getName() ?? '—',
                    'creationDate' => $modality->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $modality->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($modality->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($modality->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($modality->getLastUpdatedBy()),
                    'lastUpdatedDate' => $modality->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }

    #[Route(path: '/settings/structure/school-years/data', name: 'app_settings_structure_school_years_data')]
    public function schoolYearsData(Request $request, SchoolYearRepository $repository): JsonResponse
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
                fn (SchoolYear $schoolYear): array => [
                    'startDate' => $schoolYear->getStartDate()->format('d/m/Y'),
                    'endDate' => $schoolYear->getEndDate()->format('d/m/Y'),
                    'creationDate' => $schoolYear->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $schoolYear->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($schoolYear->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($schoolYear->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($schoolYear->getLastUpdatedBy()),
                    'lastUpdatedDate' => $schoolYear->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }

    #[Route(path: '/settings/structure/programs/data', name: 'app_settings_structure_programs_data')]
    public function programsData(Request $request, ProgramRepository $repository): JsonResponse
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
                fn (Program $program): array => [
                    'name' => $program->getName(),
                    'shortName' => $program->getShortName(),
                    'cohortName' => $program->getCohort()->getName(),
                    'schoolYearLabel' => sprintf('%s - %s', $program->getSchoolYear()->getStartDate()->format('Y'), $program->getSchoolYear()->getEndDate()->format('Y')),
                    'creationDate' => $program->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $program->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($program->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($program->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($program->getLastUpdatedBy()),
                    'lastUpdatedDate' => $program->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }

    #[Route(path: '/settings/structure/periods/data', name: 'app_settings_structure_periods_data')]
    public function periodsData(Request $request, PeriodRepository $repository): JsonResponse
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
                fn (Period $period): array => [
                    'name' => $period->getName(),
                    'startDate' => $period->getStartDate()->format('d/m/Y'),
                    'endDate' => $period->getEndDate()->format('d/m/Y'),
                    'creationDate' => $period->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $period->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($period->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($period->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($period->getLastUpdatedBy()),
                    'lastUpdatedDate' => $period->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
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

    /** @param Collection<int, Program> $programs */
    private function programNames(Collection $programs): string
    {
        return implode(', ', array_map(fn (Program $program): string => $program->getName(), $programs->toArray()));
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    private function userLabel(?User $user): string
    {
        if (null === $user) {
            return '—';
        }

        return $user->getDisplayName() ?? $user->getUsername();
    }
}
