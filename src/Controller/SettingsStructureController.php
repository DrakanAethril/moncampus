<?php

namespace App\Controller;

use App\Entity\Cohort;
use App\Entity\LessonType;
use App\Entity\Modality;
use App\Entity\Option;
use App\Entity\Period;
use App\Entity\PeriodGroup;
use App\Entity\PeriodType;
use App\Entity\Program;
use App\Entity\Room;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\SkillLevel;
use App\Entity\Track;
use App\Entity\User;
use App\Form\CohortType;
use App\Form\LessonTypeType;
use App\Form\ModalityType;
use App\Form\OptionType;
use App\Form\PeriodGroupType;
use App\Form\PeriodType as PeriodFormType;
use App\Form\PeriodTypeType;
use App\Form\ProgramType;
use App\Form\RoomType;
use App\Form\SchoolYearType;
use App\Form\SectionType;
use App\Form\SkillLevelType;
use App\Form\TrackType;
use App\Repository\CohortRepository;
use App\Repository\LessonTypeRepository;
use App\Repository\ModalityRepository;
use App\Repository\OptionRepository;
use App\Repository\PeriodGroupRepository;
use App\Repository\PeriodRepository;
use App\Repository\PeriodTypeRepository;
use App\Repository\ProgramRepository;
use App\Repository\RoomRepository;
use App\Repository\SchoolYearRepository;
use App\Repository\SectionRepository;
use App\Repository\SkillLevelRepository;
use App\Repository\TrackRepository;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class SettingsStructureController extends AbstractController
{
    // Which of the two settings/*.html.twig shells (see renderTab()) each tab's content renders
    // under - "configuration" for things that essentially never change between school years,
    // "pedagogique" for things tied to a specific school year. Purely a presentation grouping:
    // every route/path below still lives under the historical "structure" naming, only the shell
    // template and top-level nav entry differ.
    private const array TAB_GROUPS = [
        'sections' => 'configuration',
        'tracks' => 'configuration',
        'cohorts' => 'configuration',
        'rooms' => 'configuration',
        'options' => 'configuration',
        'modalities' => 'configuration',
        'lesson_types' => 'configuration',
        'skill_levels' => 'configuration',
        'period_types' => 'configuration',
        'school_years' => 'pedagogique',
        'programs' => 'pedagogique',
        'period_groups' => 'pedagogique',
    ];

    // Each tab has its own route so navigating between tabs only loads that tab's content
    // (and fires only that tab's DataTables request) instead of rendering all 7 tabs' tables
    // up front.
    #[Route(path: '/settings/configuration', name: 'app_settings_configuration')]
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

    #[Route(path: '/settings/pedagogique', name: 'app_settings_pedagogique')]
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

    #[Route(path: '/settings/structure/period-groups', name: 'app_settings_structure_period_groups')]
    public function periodGroupsTab(): Response
    {
        return $this->renderTab('period_groups');
    }

    #[Route(path: '/settings/structure/lesson-types', name: 'app_settings_structure_lesson_types')]
    public function lessonTypesTab(): Response
    {
        return $this->renderTab('lesson_types');
    }

    // Formerly a tab on SettingsInternshipController's "Livret Alternant" page - moved here since
    // it's a rarely-changes-between-years setting, not tied to this year's Livret Alternant
    // content (see App\Entity\SkillLevel::isGlobal() for the program-level opt-out this
    // establishment-wide default list backs).
    #[Route(path: '/settings/structure/skill-levels', name: 'app_settings_structure_skill_levels')]
    public function skillLevelsTab(): Response
    {
        return $this->renderTab('skill_levels');
    }

    // Establishment-wide lookup of what kind of Period this is (Scolaire/Entreprise/Vacances) -
    // rarely changes between years, same tier as lesson types/skill levels.
    #[Route(path: '/settings/structure/period-types', name: 'app_settings_structure_period_types')]
    public function periodTypesTab(): Response
    {
        return $this->renderTab('period_types');
    }

    private function renderTab(string $tab): Response
    {
        return $this->render('settings/'.self::TAB_GROUPS[$tab].'.html.twig', [
            'activeTab' => $tab,
        ]);
    }

    // Each of the 10 "form" actions below serves both /new and /{id}/edit under one route/method
    // pair, reusing the same FormType and the same *_new.html.twig template for create and edit
    // (the "isEdit" flag only changes the page heading and which audit fields get stamped) -
    // this is the "no code duplication" reuse the edit feature asked for.

    #[Route(path: '/settings/structure/sections/new', name: 'app_settings_structure_sections_new')]
    #[Route(path: '/settings/structure/sections/{id}/edit', name: 'app_settings_structure_sections_edit')]
    public function sectionForm(Request $request, EntityManagerInterface $entityManager, SectionRepository $repository, ?int $id = null): Response
    {
        $section = null !== $id ? $this->findOrNotFound($repository, $id) : null;
        $isEdit = null !== $section;

        $form = $this->createForm(SectionType::class, $section);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'sectionUpdatedFlashMessage' : 'sectionCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_sections');
        }

        return $this->render('settings/section_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/settings/structure/sections/{id}/deactivate', name: 'app_settings_structure_sections_deactivate', methods: ['POST'])]
    public function deactivateSection(Request $request, EntityManagerInterface $entityManager, SectionRepository $repository, int $id): JsonResponse
    {
        $section = $this->findOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $section->setInactiveDate(new \DateTimeImmutable());
        $section->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
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

    #[Route(path: '/settings/structure/cohorts/new', name: 'app_settings_structure_cohorts_new')]
    #[Route(path: '/settings/structure/cohorts/{id}/edit', name: 'app_settings_structure_cohorts_edit')]
    public function cohortForm(Request $request, EntityManagerInterface $entityManager, CohortRepository $repository, ?int $id = null): Response
    {
        $cohort = null !== $id ? $this->findOrNotFound($repository, $id) : null;
        $isEdit = null !== $cohort;

        $form = $this->createForm(CohortType::class, $cohort);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'cohortUpdatedFlashMessage' : 'cohortCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_cohorts');
        }

        return $this->render('settings/cohort_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/settings/structure/cohorts/{id}/deactivate', name: 'app_settings_structure_cohorts_deactivate', methods: ['POST'])]
    public function deactivateCohort(Request $request, EntityManagerInterface $entityManager, CohortRepository $repository, int $id): JsonResponse
    {
        $cohort = $this->findOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $cohort->setInactiveDate(new \DateTimeImmutable());
        $cohort->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
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

    #[Route(path: '/settings/structure/options/new', name: 'app_settings_structure_options_new')]
    #[Route(path: '/settings/structure/options/{id}/edit', name: 'app_settings_structure_options_edit')]
    public function optionForm(Request $request, EntityManagerInterface $entityManager, OptionRepository $repository, ?int $id = null): Response
    {
        $option = null !== $id ? $this->findOrNotFound($repository, $id) : null;
        $isEdit = null !== $option;

        $form = $this->createForm(OptionType::class, $option);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'optionUpdatedFlashMessage' : 'optionCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_options');
        }

        return $this->render('settings/option_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/settings/structure/options/{id}/deactivate', name: 'app_settings_structure_options_deactivate', methods: ['POST'])]
    public function deactivateOption(Request $request, EntityManagerInterface $entityManager, OptionRepository $repository, int $id): JsonResponse
    {
        $option = $this->findOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $option->setInactiveDate(new \DateTimeImmutable());
        $option->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/settings/structure/modalities/new', name: 'app_settings_structure_modalities_new')]
    #[Route(path: '/settings/structure/modalities/{id}/edit', name: 'app_settings_structure_modalities_edit')]
    public function modalityForm(Request $request, EntityManagerInterface $entityManager, ModalityRepository $repository, ?int $id = null): Response
    {
        $modality = null !== $id ? $this->findOrNotFound($repository, $id) : null;
        $isEdit = null !== $modality;

        $form = $this->createForm(ModalityType::class, $modality);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'modalityUpdatedFlashMessage' : 'modalityCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_modalities');
        }

        return $this->render('settings/modality_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/settings/structure/modalities/{id}/deactivate', name: 'app_settings_structure_modalities_deactivate', methods: ['POST'])]
    public function deactivateModality(Request $request, EntityManagerInterface $entityManager, ModalityRepository $repository, int $id): JsonResponse
    {
        $modality = $this->findOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $modality->setInactiveDate(new \DateTimeImmutable());
        $modality->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/settings/structure/school-years/new', name: 'app_settings_structure_school_years_new')]
    #[Route(path: '/settings/structure/school-years/{id}/edit', name: 'app_settings_structure_school_years_edit')]
    public function schoolYearForm(Request $request, EntityManagerInterface $entityManager, SchoolYearRepository $repository, ?int $id = null): Response
    {
        $schoolYear = null !== $id ? $this->findOrNotFound($repository, $id) : null;
        $isEdit = null !== $schoolYear;

        $form = $this->createForm(SchoolYearType::class, $schoolYear);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'schoolYearUpdatedFlashMessage' : 'schoolYearCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_school_years');
        }

        return $this->render('settings/school_year_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/settings/structure/school-years/{id}/deactivate', name: 'app_settings_structure_school_years_deactivate', methods: ['POST'])]
    public function deactivateSchoolYear(Request $request, EntityManagerInterface $entityManager, SchoolYearRepository $repository, int $id): JsonResponse
    {
        $schoolYear = $this->findOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $schoolYear->setInactiveDate(new \DateTimeImmutable());
        $schoolYear->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/settings/structure/programs/new', name: 'app_settings_structure_programs_new')]
    #[Route(path: '/settings/structure/programs/{id}/edit', name: 'app_settings_structure_programs_edit')]
    public function programForm(Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, ?int $id = null): Response
    {
        $isEdit = null !== $id;
        // A real Program backs the "new" form too, not null - ProgramType's management-enabled
        // checkboxes are ordinary mapped fields that read their initial view state straight off
        // the model, so only a real instance (picking up the `= true` property defaults) renders
        // them pre-checked. Cohort/SchoolYear are nulled back out right after construction (the
        // constructor requires *some* instance) so the EntityType fields still render their
        // normal "nothing selected" placeholder - a non-persisted entity as a field's current
        // value trips EntityType's "must be managed" check.
        $program = $isEdit
            ? $this->findOrNotFound($repository, $id)
            : (new Program('', '', new Cohort('', new Track('', new Section(''))), new SchoolYear(new \DateTimeImmutable(), new \DateTimeImmutable())))
                ->setCohort(null)
                ->setSchoolYear(null)
                ->setPeriodGroup(null);

        $form = $this->createForm(ProgramType::class, $program);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'programUpdatedFlashMessage' : 'programCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_programs');
        }

        return $this->render('settings/program_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/settings/structure/programs/{id}/deactivate', name: 'app_settings_structure_programs_deactivate', methods: ['POST'])]
    public function deactivateProgram(Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, int $id): JsonResponse
    {
        $program = $this->findOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $program->setInactiveDate(new \DateTimeImmutable());
        $program->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/settings/structure/period-groups/new', name: 'app_settings_structure_period_groups_new')]
    #[Route(path: '/settings/structure/period-groups/{id}/edit', name: 'app_settings_structure_period_groups_edit')]
    public function periodGroupForm(Request $request, EntityManagerInterface $entityManager, PeriodGroupRepository $repository, ?int $id = null): Response
    {
        $periodGroup = null !== $id ? $this->findOrNotFound($repository, $id) : null;
        $isEdit = null !== $periodGroup;

        $form = $this->createForm(PeriodGroupType::class, $periodGroup);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'periodGroupUpdatedFlashMessage' : 'periodGroupCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_period_groups');
        }

        return $this->render('settings/period_group_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/settings/structure/period-groups/{id}/deactivate', name: 'app_settings_structure_period_groups_deactivate', methods: ['POST'])]
    public function deactivatePeriodGroup(Request $request, EntityManagerInterface $entityManager, PeriodGroupRepository $repository, int $id): JsonResponse
    {
        $periodGroup = $this->findOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $periodGroup->setInactiveDate(new \DateTimeImmutable());
        $periodGroup->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    // Clones a PeriodGroup and its active Periods only (deactivated/historical periods aren't
    // carried over) - kept on the same SchoolYear as the source; staff re-assign that afterward
    // via the normal edit form if duplicating into a new year. Navigates straight into the
    // duplicate's periods list (see the frontend's redirectUrl handling in performAction()) so
    // staff can immediately review/adjust dates rather than landing back on the flat group list.
    #[Route(path: '/settings/structure/period-groups/{id}/duplicate', name: 'app_settings_structure_period_groups_duplicate', methods: ['POST'])]
    public function duplicatePeriodGroup(Request $request, EntityManagerInterface $entityManager, PeriodGroupRepository $repository, PeriodRepository $periodRepository, TranslatorInterface $translator, int $id): JsonResponse
    {
        $source = $this->findOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $copy = new PeriodGroup(sprintf($translator->trans('periodGroupDuplicateNameFormat'), $source->getName()), $source->getSchoolYear());
        $copy->setCreatedBy($this->currentUser());
        $entityManager->persist($copy);

        foreach ($periodRepository->findAllActiveForPeriodGroup($source) as $period) {
            $periodCopy = new Period($period->getName(), $period->getStartDate(), $period->getEndDate(), $period->getType(), $copy);
            $periodCopy->setCreatedBy($this->currentUser());
            $entityManager->persist($periodCopy);
        }

        $entityManager->flush();

        $this->addFlash('success', 'periodGroupDuplicatedFlashMessage');

        return $this->json([
            'success' => true,
            'redirectUrl' => $this->generateUrl('app_settings_structure_period_groups_periods', ['groupId' => $copy->getId()]),
        ]);
    }

    #[Route(path: '/settings/structure/period-groups/{groupId}/periods', name: 'app_settings_structure_period_groups_periods')]
    public function periodGroupPeriodsList(int $groupId, PeriodGroupRepository $repository): Response
    {
        $periodGroup = $this->findOrNotFound($repository, $groupId);

        return $this->render('settings/period_group_periods.html.twig', [
            'periodGroup' => $periodGroup,
        ]);
    }

    #[Route(path: '/settings/structure/period-groups/{groupId}/periods/new', name: 'app_settings_structure_period_groups_periods_new')]
    #[Route(path: '/settings/structure/period-groups/{groupId}/periods/{id}/edit', name: 'app_settings_structure_period_groups_periods_edit')]
    public function periodGroupPeriodForm(int $groupId, Request $request, EntityManagerInterface $entityManager, PeriodGroupRepository $repository, PeriodRepository $periodRepository, ?int $id = null): Response
    {
        $periodGroup = $this->findOrNotFound($repository, $groupId);
        $period = null !== $id ? $this->findPeriodOrNotFound($periodRepository, $periodGroup, $id) : null;
        $isEdit = null !== $period;

        $form = $this->createForm(PeriodFormType::class, $period, ['periodGroup' => $periodGroup]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'periodUpdatedFlashMessage' : 'periodCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_period_groups_periods', ['groupId' => $periodGroup->getId()]);
        }

        return $this->render('settings/period_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'periodGroup' => $periodGroup,
        ]);
    }

    #[Route(path: '/settings/structure/period-groups/{groupId}/periods/{id}/deactivate', name: 'app_settings_structure_period_groups_periods_deactivate', methods: ['POST'])]
    public function deactivatePeriodGroupPeriod(int $groupId, int $id, Request $request, EntityManagerInterface $entityManager, PeriodGroupRepository $repository, PeriodRepository $periodRepository): JsonResponse
    {
        $periodGroup = $this->findOrNotFound($repository, $groupId);
        $period = $this->findPeriodOrNotFound($periodRepository, $periodGroup, $id);
        $this->assertValidDeactivateToken($request);

        $period->setInactiveDate(new \DateTimeImmutable());
        $period->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/settings/structure/lesson-types/new', name: 'app_settings_structure_lesson_types_new')]
    #[Route(path: '/settings/structure/lesson-types/{id}/edit', name: 'app_settings_structure_lesson_types_edit')]
    public function lessonTypeForm(Request $request, EntityManagerInterface $entityManager, LessonTypeRepository $repository, ?int $id = null): Response
    {
        $lessonType = null !== $id ? $this->findOrNotFound($repository, $id) : null;
        $isEdit = null !== $lessonType;

        $form = $this->createForm(LessonTypeType::class, $lessonType);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'lessonTypeUpdatedFlashMessage' : 'lessonTypeCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_lesson_types');
        }

        return $this->render('settings/lesson_type_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/settings/structure/lesson-types/{id}/deactivate', name: 'app_settings_structure_lesson_types_deactivate', methods: ['POST'])]
    public function deactivateLessonType(Request $request, EntityManagerInterface $entityManager, LessonTypeRepository $repository, int $id): JsonResponse
    {
        $lessonType = $this->findOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $lessonType->setInactiveDate(new \DateTimeImmutable());
        $lessonType->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/settings/structure/skill-levels/new', name: 'app_settings_structure_skill_levels_new')]
    #[Route(path: '/settings/structure/skill-levels/{id}/edit', name: 'app_settings_structure_skill_levels_edit')]
    public function skillLevelForm(Request $request, EntityManagerInterface $entityManager, SkillLevelRepository $repository, ?int $id = null): Response
    {
        $skillLevel = null !== $id ? $this->findGlobalSkillLevelOrNotFound($repository, $id) : null;
        $isEdit = null !== $skillLevel;

        $form = $this->createForm(SkillLevelType::class, $skillLevel ?? new SkillLevel());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'skillLevelUpdatedFlashMessage' : 'skillLevelCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_skill_levels');
        }

        return $this->render('settings/skill_level_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/settings/structure/skill-levels/{id}/deactivate', name: 'app_settings_structure_skill_levels_deactivate', methods: ['POST'])]
    public function deactivateSkillLevel(Request $request, EntityManagerInterface $entityManager, SkillLevelRepository $repository, int $id): JsonResponse
    {
        $skillLevel = $this->findGlobalSkillLevelOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $skillLevel->setInactiveDate(new \DateTimeImmutable());
        $skillLevel->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/settings/structure/period-types/new', name: 'app_settings_structure_period_types_new')]
    #[Route(path: '/settings/structure/period-types/{id}/edit', name: 'app_settings_structure_period_types_edit')]
    public function periodTypeForm(Request $request, EntityManagerInterface $entityManager, PeriodTypeRepository $repository, ?int $id = null): Response
    {
        $periodType = null !== $id ? $this->findOrNotFound($repository, $id) : null;
        $isEdit = null !== $periodType;

        $form = $this->createForm(PeriodTypeType::class, $periodType);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'periodTypeUpdatedFlashMessage' : 'periodTypeCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_period_types');
        }

        return $this->render('settings/period_type_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/settings/structure/period-types/{id}/deactivate', name: 'app_settings_structure_period_types_deactivate', methods: ['POST'])]
    public function deactivatePeriodType(Request $request, EntityManagerInterface $entityManager, PeriodTypeRepository $repository, int $id): JsonResponse
    {
        $periodType = $this->findOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $periodType->setInactiveDate(new \DateTimeImmutable());
        $periodType->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/settings/structure/sections/data', name: 'app_settings_structure_sections_data')]
    public function sectionsData(Request $request, SectionRepository $repository): JsonResponse
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
                fn (Section $section): array => [
                    'id' => $section->getId(),
                    'isInactive' => null !== $section->getInactiveDate(),
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

    #[Route(path: '/settings/structure/cohorts/data', name: 'app_settings_structure_cohorts_data')]
    public function cohortsData(Request $request, CohortRepository $repository): JsonResponse
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
                fn (Cohort $cohort): array => [
                    'id' => $cohort->getId(),
                    'isInactive' => null !== $cohort->getInactiveDate(),
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

    #[Route(path: '/settings/structure/options/data', name: 'app_settings_structure_options_data')]
    public function optionsData(Request $request, OptionRepository $repository): JsonResponse
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
                fn (Option $option): array => [
                    'id' => $option->getId(),
                    'isInactive' => null !== $option->getInactiveDate(),
                    'name' => $option->getName(),
                    'shortName' => $option->getShortName(),
                    'slug' => $option->getSlug(),
                    'color' => $option->getColor(),
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
        [$draw, $start, $length, $search, $includeInactive] = $this->readDataTableParams($request);

        $total = $repository->countAll(null, $includeInactive);
        $filteredTotal = '' !== $search ? $repository->countAll($search, $includeInactive) : $total;
        $rows = $repository->findPageOrderedByMostRecent($start, $length, '' !== $search ? $search : null, $includeInactive);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (Modality $modality): array => [
                    'id' => $modality->getId(),
                    'isInactive' => null !== $modality->getInactiveDate(),
                    'name' => $modality->getName(),
                    'slug' => $modality->getSlug(),
                    'color' => $modality->getColor(),
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
        [$draw, $start, $length, $search, $includeInactive] = $this->readDataTableParams($request);

        $total = $repository->countAll(null, $includeInactive);
        $filteredTotal = '' !== $search ? $repository->countAll($search, $includeInactive) : $total;
        $rows = $repository->findPageOrderedByMostRecent($start, $length, '' !== $search ? $search : null, $includeInactive);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (SchoolYear $schoolYear): array => [
                    'id' => $schoolYear->getId(),
                    'isInactive' => null !== $schoolYear->getInactiveDate(),
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
        [$draw, $start, $length, $search, $includeInactive] = $this->readDataTableParams($request);

        $total = $repository->countAll(null, $includeInactive);
        $filteredTotal = '' !== $search ? $repository->countAll($search, $includeInactive) : $total;
        $rows = $repository->findPageOrderedByMostRecent($start, $length, '' !== $search ? $search : null, $includeInactive);
        $repository->hydrateOptionsAndModalities($rows);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (Program $program): array => [
                    'id' => $program->getId(),
                    'isInactive' => null !== $program->getInactiveDate(),
                    'name' => $program->getName(),
                    'shortName' => $program->getShortName(),
                    'cohortName' => $program->getCohort()->getName(),
                    'schoolYearLabel' => sprintf('%s - %s', $program->getSchoolYear()->getStartDate()->format('Y'), $program->getSchoolYear()->getEndDate()->format('Y')),
                    'periodGroupName' => $program->getPeriodGroup()?->getName() ?? '—',
                    'optionNames' => $this->optionNames($program->getOptions()),
                    'modalityNames' => $this->modalityNames($program->getModalities()),
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

    #[Route(path: '/settings/structure/period-groups/data', name: 'app_settings_structure_period_groups_data')]
    public function periodGroupsData(Request $request, PeriodGroupRepository $repository): JsonResponse
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
                fn (PeriodGroup $periodGroup): array => [
                    'id' => $periodGroup->getId(),
                    'isInactive' => null !== $periodGroup->getInactiveDate(),
                    // Rendered as trusted HTML by the 'html' render keyword on this column (see
                    // _period_groups_content.html.twig) - the default column render escapes it.
                    'name' => sprintf(
                        '<a href="%s">%s</a>',
                        htmlspecialchars($this->generateUrl('app_settings_structure_period_groups_periods', ['groupId' => $periodGroup->getId()])),
                        htmlspecialchars($periodGroup->getName()),
                    ),
                    'schoolYearLabel' => sprintf('%s - %s', $periodGroup->getSchoolYear()->getStartDate()->format('Y'), $periodGroup->getSchoolYear()->getEndDate()->format('Y')),
                    'creationDate' => $periodGroup->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $periodGroup->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($periodGroup->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($periodGroup->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($periodGroup->getLastUpdatedBy()),
                    'lastUpdatedDate' => $periodGroup->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }

    #[Route(path: '/settings/structure/period-groups/{groupId}/periods/data', name: 'app_settings_structure_period_groups_periods_data')]
    public function periodGroupPeriodsData(int $groupId, Request $request, PeriodGroupRepository $repository, PeriodRepository $periodRepository): JsonResponse
    {
        $periodGroup = $this->findOrNotFound($repository, $groupId);
        [$draw, $start, $length, $search, $includeInactive] = $this->readDataTableParams($request);

        $total = $periodRepository->countAllForPeriodGroup($periodGroup, null, $includeInactive);
        $filteredTotal = '' !== $search ? $periodRepository->countAllForPeriodGroup($periodGroup, $search, $includeInactive) : $total;
        $rows = $periodRepository->findPageForPeriodGroupOrderedByMostRecent($periodGroup, $start, $length, '' !== $search ? $search : null, $includeInactive);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (Period $period): array => [
                    'id' => $period->getId(),
                    'isInactive' => null !== $period->getInactiveDate(),
                    'name' => $period->getName(),
                    'typeName' => $period->getType()?->getName() ?? '—',
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

    #[Route(path: '/settings/structure/period-types/data', name: 'app_settings_structure_period_types_data')]
    public function periodTypesData(Request $request, PeriodTypeRepository $repository): JsonResponse
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
                fn (PeriodType $periodType): array => [
                    'id' => $periodType->getId(),
                    'isInactive' => null !== $periodType->getInactiveDate(),
                    'name' => $periodType->getName(),
                    'color' => $periodType->getColor(),
                    'creationDate' => $periodType->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $periodType->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($periodType->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($periodType->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($periodType->getLastUpdatedBy()),
                    'lastUpdatedDate' => $periodType->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }

    #[Route(path: '/settings/structure/lesson-types/data', name: 'app_settings_structure_lesson_types_data')]
    public function lessonTypesData(Request $request, LessonTypeRepository $repository): JsonResponse
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
                fn (LessonType $lessonType): array => [
                    'id' => $lessonType->getId(),
                    'isInactive' => null !== $lessonType->getInactiveDate(),
                    'name' => $lessonType->getName(),
                    'agendaColor' => $lessonType->getAgendaColor(),
                    'defaultCost' => $lessonType->getDefaultCost() ?? '—',
                ],
                $rows,
            ),
        ]);
    }

    #[Route(path: '/settings/structure/skill-levels/data', name: 'app_settings_structure_skill_levels_data')]
    public function skillLevelsData(Request $request, SkillLevelRepository $repository): JsonResponse
    {
        [$draw, $start, $length, $search, $includeInactive] = $this->readDataTableParams($request);

        $total = $repository->countAllGlobal(null, $includeInactive);
        $filteredTotal = '' !== $search ? $repository->countAllGlobal($search, $includeInactive) : $total;
        $rows = $repository->findPageGlobalOrderedByMostRecent($start, $length, '' !== $search ? $search : null, $includeInactive);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (SkillLevel $skillLevel): array => [
                    'id' => $skillLevel->getId(),
                    'isInactive' => null !== $skillLevel->getInactiveDate(),
                    'label' => $skillLevel->getLabel(),
                    'color' => $skillLevel->getColor(),
                    'orderIndex' => $skillLevel->getOrderIndex(),
                    'creationDate' => $skillLevel->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $skillLevel->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($skillLevel->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($skillLevel->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($skillLevel->getLastUpdatedBy()),
                    'lastUpdatedDate' => $skillLevel->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }

    /** @return array{0: int, 1: int, 2: int, 3: string, 4: bool} */
    private function readDataTableParams(Request $request): array
    {
        $draw = $request->query->getInt('draw', 1);
        $start = max(0, $request->query->getInt('start', 0));
        $length = $request->query->getInt('length', 10);
        $length = $length > 0 ? min($length, 50) : 10;
        $search = trim((string) ($request->query->all('search')['value'] ?? ''));
        $includeInactive = $request->query->getBoolean('includeInactive');

        return [$draw, $start, $length, $search, $includeInactive];
    }

    /** @param Collection<int, Option> $options */
    private function optionNames(Collection $options): string
    {
        return implode(', ', array_map(fn (Option $option): string => $option->getShortName(), $options->toArray()));
    }

    /** @param Collection<int, Modality> $modalities */
    private function modalityNames(Collection $modalities): string
    {
        return implode(', ', array_map(fn (Modality $modality): string => $modality->getName(), $modalities->toArray()));
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

    /**
     * @template T of object
     *
     * @param ObjectRepository<T> $repository
     *
     * @return T
     */
    private function findOrNotFound(ObjectRepository $repository, int $id): object
    {
        return $repository->find($id) ?? throw $this->createNotFoundException();
    }

    // Unlike findOrNotFound() above, SkillLevel rows aren't all fair game here - a
    // Program-scoped level (see SkillLevel::isGlobal()) must not be editable/
    // deactivatable from this establishment-wide screen.
    private function findGlobalSkillLevelOrNotFound(SkillLevelRepository $repository, int $id): SkillLevel
    {
        $skillLevel = $repository->find($id) ?? throw $this->createNotFoundException();

        if (!$skillLevel->isGlobal()) {
            throw $this->createNotFoundException();
        }

        return $skillLevel;
    }

    private function findPeriodOrNotFound(PeriodRepository $repository, PeriodGroup $periodGroup, int $id): Period
    {
        $period = $repository->find($id) ?? throw $this->createNotFoundException();

        if ($period->getPeriodGroup()?->getId() !== $periodGroup->getId()) {
            throw $this->createNotFoundException();
        }

        return $period;
    }

    private function stampAuditFields(object $entity, bool $isEdit): void
    {
        if ($isEdit) {
            $entity->setLastUpdatedBy($this->currentUser());
            $entity->setLastUpdatedDate(new \DateTimeImmutable());
        } else {
            $entity->setCreatedBy($this->currentUser());
        }
    }

    private function assertValidDeactivateToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('structure_deactivate', $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
