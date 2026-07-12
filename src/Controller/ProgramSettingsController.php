<?php

namespace App\Controller;

use App\Entity\Option;
use App\Entity\Program;
use App\Entity\ProgramFinancialItem;
use App\Entity\ProgramLessonTypeCost;
use App\Entity\ProgramReport;
use App\Entity\ProgramStudentOption;
use App\Entity\ProgramTeacherOption;
use App\Entity\Skill;
use App\Entity\SkillGroup;
use App\Entity\SkillLevel;
use App\Entity\User;
use App\Enum\FinancialItemSource;
use App\Form\MemberOptionsType;
use App\Form\ProgramFinancialItemType;
use App\Form\ProgramReportType;
use App\Form\SkillGroupType;
use App\Form\SkillLevelType;
use App\Form\SkillType;
use App\Repository\LessonTypeRepository;
use App\Repository\ProgramFinancialItemRepository;
use App\Repository\ProgramLessonTypeCostRepository;
use App\Repository\ProgramReportRepository;
use App\Repository\ProgramRepository;
use App\Repository\ProgramStudentOptionRepository;
use App\Repository\ProgramTeacherOptionRepository;
use App\Repository\SkillGroupRepository;
use App\Repository\SkillLevelRepository;
use App\Repository\SkillRepository;
use App\Repository\UserRepository;
use App\Service\ProgramFinancialCalculator;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// The "Programme" page reached via the Paramétrage submenu - reuses the same tab shell pattern
// as SettingsStructureController (each tab its own route, shared settings/configuration.html.twig/
// pedagogique.html.twig-style shell, only the active tab's content/data ever loads). Staff/admin only, same as the
// rest of the structure management area. Sibling of ProgramTimetableSettingsController
// (Emploi du temps) and ProgramInternshipController (Livret de l'alternant) - the three groups
// the "Paramétrage" dropend now splits into, see templates/layout/app.html.twig.
// Also hosts the "Groupes de compétences" tab (SkillGroup entity, each owning Skill rows - see
// SkillGroup::$skills) - moved here from ProgramInternshipController since it's now usable
// outside the Livret Alternant (each row carries its own visibleInBooklet/visibleInProgram
// flags), even though SkillGroup's booklet/evaluation-form use still lives there. SkillGroup/
// Skill are always this Program's own - unlike SkillLevel (see the "Niveaux de
// compétences" tab below), there's no Centre de formation/shared variant for them at all. The
// standalone TSF-export Skill/Compétences concept that used to also live here has been removed
// entirely.
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class ProgramSettingsController extends AbstractController
{
    use ProgramFeatureGuardTrait;

    private const string STUDENT_TYPE_ROLE = 'ROLE_STUDENT';
    private const string TEACHER_TYPE_ROLE = 'ROLE_TEACHER';

    #[Route(path: '/programs/{id}/settings', name: 'app_program_settings')]
    #[Route(path: '/programs/{id}/settings/students', name: 'app_program_settings_students')]
    public function studentsTab(int $id, ProgramRepository $repository): Response
    {
        return $this->renderTab($id, $repository, 'students');
    }

    #[Route(path: '/programs/{id}/settings/teachers', name: 'app_program_settings_teachers')]
    public function teachersTab(int $id, ProgramRepository $repository): Response
    {
        return $this->renderTab($id, $repository, 'teachers');
    }

    #[Route(path: '/programs/{id}/settings/skill-groups', name: 'app_program_settings_skill_groups')]
    public function skillGroupsTab(int $id, ProgramRepository $repository): Response
    {
        return $this->renderTab($id, $repository, 'skill_groups');
    }

    #[Route(path: '/programs/{id}/settings/skill-groups/new', name: 'app_program_settings_skill_groups_new')]
    #[Route(path: '/programs/{id}/settings/skill-groups/{groupId}/edit', name: 'app_program_settings_skill_groups_edit')]
    public function skillGroupForm(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, SkillGroupRepository $skillGroupRepository, ?int $groupId = null): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $isEdit = null !== $groupId;
        // A real SkillGroup backs the "new" form too, not null - visibleInBooklet/
        // visibleInProgram are ordinary mapped checkboxes that read their initial view state
        // straight off the model, so only a real instance (picking up the `= true` property
        // defaults) renders them pre-checked, same reasoning as ProgramType's management-enabled
        // checkboxes in SettingsStructureController::programForm().
        $skillGroup = $isEdit ? $this->findSkillGroupOrNotFound($skillGroupRepository, $program, $groupId) : new SkillGroup('', $program);

        $form = $this->createForm(SkillGroupType::class, $skillGroup, ['optionChoices' => $program->getOptions()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'internshipSkillGroupUpdatedFlashMessage' : 'internshipSkillGroupCreatedFlashMessage');

            return $this->redirectToRoute('app_program_settings_skill_groups', ['id' => $program->getId()]);
        }

        return $this->render('program/skill_group_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'program' => $program,
        ]);
    }

    #[Route(path: '/programs/{id}/settings/skill-groups/{groupId}/deactivate', name: 'app_program_settings_skill_groups_deactivate', methods: ['POST'])]
    public function deactivateSkillGroup(int $id, int $groupId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, SkillGroupRepository $skillGroupRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $skillGroup = $this->findSkillGroupOrNotFound($skillGroupRepository, $program, $groupId);
        $this->assertValidToken('program_settings_deactivate', $request);

        $skillGroup->setInactiveDate(new \DateTimeImmutable());
        $skillGroup->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/programs/{id}/settings/skill-groups/data', name: 'app_program_settings_skill_groups_data')]
    public function skillGroupsData(int $id, Request $request, ProgramRepository $repository, SkillGroupRepository $skillGroupRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        [$draw, $start, $length, $search, $includeInactive] = $this->readActiveFilterableDataTableParams($request);

        $total = $skillGroupRepository->countAllForProgram($program, null, $includeInactive);
        $filteredTotal = '' !== $search ? $skillGroupRepository->countAllForProgram($program, $search, $includeInactive) : $total;
        $rows = $skillGroupRepository->findPageForProgramOrderedByMostRecent($program, $start, $length, '' !== $search ? $search : null, $includeInactive);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (SkillGroup $skillGroup): array => [
                    'id' => $skillGroup->getId(),
                    'isInactive' => null !== $skillGroup->getInactiveDate(),
                    // Rendered as trusted HTML by the 'html' render keyword on this column
                    // (see _skill_groups_content.html.twig) - the default column render escapes it.
                    'label' => sprintf(
                        '<a href="%s">%s</a>',
                        htmlspecialchars($this->generateUrl('app_program_settings_skill_groups_skills', ['id' => $program->getId(), 'groupId' => $skillGroup->getId()])),
                        htmlspecialchars($skillGroup->getLabel()),
                    ),
                    'creationDate' => $skillGroup->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $skillGroup->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($skillGroup->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($skillGroup->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($skillGroup->getLastUpdatedBy()),
                    'lastUpdatedDate' => $skillGroup->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }

    #[Route(path: '/programs/{id}/settings/skill-groups/{groupId}/skills', name: 'app_program_settings_skill_groups_skills')]
    public function skillsList(int $id, int $groupId, ProgramRepository $repository, SkillGroupRepository $skillGroupRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $skillGroup = $this->findSkillGroupOrNotFound($skillGroupRepository, $program, $groupId);

        return $this->render('program/skill_group_skills.html.twig', [
            'program' => $program,
            'skillGroup' => $skillGroup,
        ]);
    }

    #[Route(path: '/programs/{id}/settings/skill-groups/{groupId}/skills/new', name: 'app_program_settings_skill_groups_skills_new')]
    #[Route(path: '/programs/{id}/settings/skill-groups/{groupId}/skills/{skillId}/edit', name: 'app_program_settings_skill_groups_skills_edit')]
    public function skillForm(int $id, int $groupId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, SkillGroupRepository $skillGroupRepository, SkillRepository $skillRepository, ?int $skillId = null): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $skillGroup = $this->findSkillGroupOrNotFound($skillGroupRepository, $program, $groupId);
        $isEdit = null !== $skillId;
        $skill = $isEdit ? $this->findSkillOrNotFound($skillRepository, $skillGroup, $skillId) : new Skill('', $skillGroup);

        $form = $this->createForm(SkillType::class, $skill);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'skillUpdatedFlashMessage' : 'skillCreatedFlashMessage');

            return $this->redirectToRoute('app_program_settings_skill_groups_skills', ['id' => $program->getId(), 'groupId' => $skillGroup->getId()]);
        }

        return $this->render('program/skill_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'program' => $program,
            'skillGroup' => $skillGroup,
        ]);
    }

    #[Route(path: '/programs/{id}/settings/skill-groups/{groupId}/skills/{skillId}/deactivate', name: 'app_program_settings_skill_groups_skills_deactivate', methods: ['POST'])]
    public function deactivateSkill(int $id, int $groupId, int $skillId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, SkillGroupRepository $skillGroupRepository, SkillRepository $skillRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $skillGroup = $this->findSkillGroupOrNotFound($skillGroupRepository, $program, $groupId);
        $skill = $this->findSkillOrNotFound($skillRepository, $skillGroup, $skillId);
        $this->assertValidToken('program_settings_deactivate', $request);

        $skill->setInactiveDate(new \DateTimeImmutable());
        $skill->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/programs/{id}/settings/skill-groups/{groupId}/skills/data', name: 'app_program_settings_skill_groups_skills_data')]
    public function skillsData(int $id, int $groupId, Request $request, ProgramRepository $repository, SkillGroupRepository $skillGroupRepository, SkillRepository $skillRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $skillGroup = $this->findSkillGroupOrNotFound($skillGroupRepository, $program, $groupId);
        [$draw, $start, $length, $search, $includeInactive] = $this->readActiveFilterableDataTableParams($request);

        $total = $skillRepository->countAllForSkillGroup($skillGroup, null, $includeInactive);
        $filteredTotal = '' !== $search ? $skillRepository->countAllForSkillGroup($skillGroup, $search, $includeInactive) : $total;
        $rows = $skillRepository->findPageForSkillGroupOrderedByMostRecent($skillGroup, $start, $length, '' !== $search ? $search : null, $includeInactive);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (Skill $skill): array => [
                    'id' => $skill->getId(),
                    'isInactive' => null !== $skill->getInactiveDate(),
                    'label' => $skill->getLabel(),
                    'creationDate' => $skill->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $skill->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($skill->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($skill->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($skill->getLastUpdatedBy()),
                    'lastUpdatedDate' => $skill->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }

    // The Centre de formation's own shared SkillLevel definition (program IS NULL, see
    // SkillLevel::isGlobal()) - every Program uses this by default, unless it opts into
    // Program::$customSkillLevelsEnabled and gets its own rows managed here instead. Unlike
    // SkillGroup/Skill, SkillLevel has no children entity, so this mirrors the old
    // skill-groups tab shape without a nested skills-list/skillsData equivalent.
    #[Route(path: '/programs/{id}/settings/skill-levels', name: 'app_program_settings_skill_levels')]
    public function skillLevelsTab(int $id, ProgramRepository $repository, SkillLevelRepository $skillLevelRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        return $this->render('program/settings.html.twig', [
            'program' => $program,
            'activeTab' => 'skill_levels',
            // Only fetched in the default (non-custom) case, to show the Centre de formation's
            // shared definition read-only - the custom case reads its own rows through the
            // DataTable instead, same as every other tab here.
            'globalSkillLevels' => $program->isCustomSkillLevelsEnabled() ? [] : $skillLevelRepository->findAllActiveGlobal(),
        ]);
    }

    // Flips Program::$customSkillLevelsEnabled - deliberately just a toggle, never a copy: per the
    // product decision behind this feature, switching a Program to custom mode starts from an
    // empty list, the Program must define the whole thing itself rather than fork the Centre de
    // formation's rows. Switching back off doesn't delete anything already entered, in case the
    // Program switches on again later.
    #[Route(path: '/programs/{id}/settings/skill-levels/toggle-custom', name: 'app_program_settings_skill_levels_toggle_custom', methods: ['POST'])]
    public function toggleCustomSkillLevels(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        // A plain HTML form POST (a toggle button, not a DataTable/fetch action) - the token
        // travels in the body like removeFinancialItem()/updateLessonTypeCosts() below, not the
        // X-CSRF-Token header assertValidToken() checks.
        if (!$this->isCsrfTokenValid('program_settings_toggle_custom_skill_levels', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $program->setCustomSkillLevelsEnabled(!$program->isCustomSkillLevelsEnabled());
        $entityManager->flush();

        $this->addFlash('success', $program->isCustomSkillLevelsEnabled() ? 'programCustomSkillLevelsEnabledFlashMessage' : 'programCustomSkillLevelsDisabledFlashMessage');

        return $this->redirectToRoute('app_program_settings_skill_levels', ['id' => $program->getId()]);
    }

    #[Route(path: '/programs/{id}/settings/skill-levels/new', name: 'app_program_settings_skill_levels_new')]
    #[Route(path: '/programs/{id}/settings/skill-levels/{levelId}/edit', name: 'app_program_settings_skill_levels_edit')]
    public function skillLevelForm(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, SkillLevelRepository $skillLevelRepository, ?int $levelId = null): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $this->assertProgramFeatureEnabled($program->isCustomSkillLevelsEnabled());
        $isEdit = null !== $levelId;
        $skillLevel = $isEdit ? $this->findSkillLevelOrNotFound($skillLevelRepository, $program, $levelId) : new SkillLevel('', '#6c757d', $program);

        $form = $this->createForm(SkillLevelType::class, $skillLevel);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'skillLevelUpdatedFlashMessage' : 'skillLevelCreatedFlashMessage');

            return $this->redirectToRoute('app_program_settings_skill_levels', ['id' => $program->getId()]);
        }

        return $this->render('program/skill_level_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'program' => $program,
        ]);
    }

    #[Route(path: '/programs/{id}/settings/skill-levels/{levelId}/deactivate', name: 'app_program_settings_skill_levels_deactivate', methods: ['POST'])]
    public function deactivateSkillLevel(int $id, int $levelId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, SkillLevelRepository $skillLevelRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $skillLevel = $this->findSkillLevelOrNotFound($skillLevelRepository, $program, $levelId);
        $this->assertValidToken('program_settings_deactivate', $request);

        $skillLevel->setInactiveDate(new \DateTimeImmutable());
        $skillLevel->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/programs/{id}/settings/skill-levels/data', name: 'app_program_settings_skill_levels_data')]
    public function skillLevelsData(int $id, Request $request, ProgramRepository $repository, SkillLevelRepository $skillLevelRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        [$draw, $start, $length, $search, $includeInactive] = $this->readActiveFilterableDataTableParams($request);

        $total = $skillLevelRepository->countAllForProgram($program, null, $includeInactive);
        $filteredTotal = '' !== $search ? $skillLevelRepository->countAllForProgram($program, $search, $includeInactive) : $total;
        $rows = $skillLevelRepository->findPageForProgramOrderedByMostRecent($program, $start, $length, '' !== $search ? $search : null, $includeInactive);

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

    #[Route(path: '/programs/{id}/settings/financial', name: 'app_program_settings_financial')]
    public function financialTab(int $id, ProgramRepository $repository, LessonTypeRepository $lessonTypeRepository, ProgramLessonTypeCostRepository $costRepository, ProgramFinancialCalculator $calculator): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $this->assertProgramFeatureEnabled($program->isFinancialManagementEnabled());
        $lessonTypes = $lessonTypeRepository->findAllActiveOrderedByName();

        return $this->render('program/settings.html.twig', [
            'program' => $program,
            'activeTab' => 'financial',
            'lessonTypes' => $lessonTypes,
            'financialTotals' => $calculator->computeTotals($program),
            'overridesByLessonTypeId' => $costRepository->findCostMapForProgram($program),
        ]);
    }

    #[Route(path: '/programs/{id}/settings/reports', name: 'app_program_settings_reports')]
    public function reportsTab(int $id, ProgramRepository $repository): Response
    {
        return $this->renderTab($id, $repository, 'reports');
    }

    #[Route(path: '/programs/{id}/settings/students/data', name: 'app_program_settings_students_data')]
    public function studentsData(int $id, Request $request, ProgramRepository $repository, ProgramStudentOptionRepository $studentOptionRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $optionsByStudentId = $program->getOptions()->isEmpty() ? null : $studentOptionRepository->findOptionsByStudentForProgram($program);

        return $this->membersData($request, $program->getStudents(), $optionsByStudentId);
    }

    #[Route(path: '/programs/{id}/settings/teachers/data', name: 'app_program_settings_teachers_data')]
    public function teachersData(int $id, Request $request, ProgramRepository $repository, ProgramTeacherOptionRepository $teacherOptionRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $optionsByTeacherId = $program->getOptions()->isEmpty() ? null : $teacherOptionRepository->findOptionsByTeacherForProgram($program);

        return $this->membersData($request, $program->getTeachers(), $optionsByTeacherId);
    }

    #[Route(path: '/programs/{id}/settings/students/add', name: 'app_program_settings_students_add')]
    public function addStudentsPage(int $id, ProgramRepository $repository): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        return $this->render('program/settings/add.html.twig', [
            'program' => $program,
            'memberType' => 'students',
        ]);
    }

    #[Route(path: '/programs/{id}/settings/teachers/add', name: 'app_program_settings_teachers_add')]
    public function addTeachersPage(int $id, ProgramRepository $repository): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        return $this->render('program/settings/add.html.twig', [
            'program' => $program,
            'memberType' => 'teachers',
        ]);
    }

    #[Route(path: '/programs/{id}/settings/students/add/data', name: 'app_program_settings_students_add_data')]
    public function addStudentsData(int $id, Request $request, ProgramRepository $repository, UserRepository $userRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);

        return $this->candidatesData($request, $program, $program->getStudents(), self::STUDENT_TYPE_ROLE, $userRepository);
    }

    #[Route(path: '/programs/{id}/settings/teachers/add/data', name: 'app_program_settings_teachers_add_data')]
    public function addTeachersData(int $id, Request $request, ProgramRepository $repository, UserRepository $userRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);

        return $this->candidatesData($request, $program, $program->getTeachers(), self::TEACHER_TYPE_ROLE, $userRepository);
    }

    #[Route(path: '/programs/{id}/settings/students/add/{userId}', name: 'app_program_settings_students_add_submit', methods: ['POST'])]
    public function addStudent(int $id, int $userId, Request $request, ProgramRepository $repository, UserRepository $userRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $user = $userRepository->find($userId) ?? throw $this->createNotFoundException();
        $this->assertValidToken('program_settings_add', $request);

        $program->addStudent($user);
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/programs/{id}/settings/teachers/add/{userId}', name: 'app_program_settings_teachers_add_submit', methods: ['POST'])]
    public function addTeacher(int $id, int $userId, Request $request, ProgramRepository $repository, UserRepository $userRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $user = $userRepository->find($userId) ?? throw $this->createNotFoundException();
        $this->assertValidToken('program_settings_add', $request);

        $program->addTeacher($user);
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/programs/{id}/settings/students/remove/{userId}', name: 'app_program_settings_students_remove_submit', methods: ['POST'])]
    public function removeStudent(int $id, int $userId, Request $request, ProgramRepository $repository, UserRepository $userRepository, ProgramStudentOptionRepository $studentOptionRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $user = $userRepository->find($userId) ?? throw $this->createNotFoundException();
        $this->assertValidToken('program_settings_remove', $request);

        foreach ($studentOptionRepository->findAllForProgramAndStudent($program, $user) as $link) {
            $entityManager->remove($link);
        }

        $program->removeStudent($user);
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/programs/{id}/settings/teachers/remove/{userId}', name: 'app_program_settings_teachers_remove_submit', methods: ['POST'])]
    public function removeTeacher(int $id, int $userId, Request $request, ProgramRepository $repository, UserRepository $userRepository, ProgramTeacherOptionRepository $teacherOptionRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $user = $userRepository->find($userId) ?? throw $this->createNotFoundException();
        $this->assertValidToken('program_settings_remove', $request);

        foreach ($teacherOptionRepository->findAllForProgramAndTeacher($program, $user) as $link) {
            $entityManager->remove($link);
        }

        $program->removeTeacher($user);
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/programs/{id}/settings/students/{userId}/options', name: 'app_program_settings_students_options')]
    public function studentOptionsForm(int $id, int $userId, Request $request, ProgramRepository $repository, UserRepository $userRepository, ProgramStudentOptionRepository $studentOptionRepository, EntityManagerInterface $entityManager): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $student = $userRepository->find($userId) ?? throw $this->createNotFoundException();

        if (!$program->getStudents()->contains($student)) {
            throw $this->createNotFoundException();
        }

        $currentOptions = $studentOptionRepository->findOptionsForStudent($program, $student);
        $form = $this->createForm(MemberOptionsType::class, ['options' => $currentOptions], ['program' => $program]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $selectedOptions = $form->get('options')->getData();
            $selectedIds = array_map(static fn (Option $option): int => $option->getId(), $selectedOptions);
            $currentIds = array_map(static fn (Option $option): int => $option->getId(), $currentOptions);

            foreach ($studentOptionRepository->findAllForProgramAndStudent($program, $student) as $link) {
                if (!in_array($link->getOption()->getId(), $selectedIds, true)) {
                    $entityManager->remove($link);
                }
            }

            foreach ($selectedOptions as $option) {
                if (!in_array($option->getId(), $currentIds, true)) {
                    $entityManager->persist(new ProgramStudentOption($program, $student, $option));
                }
            }

            $entityManager->flush();
            $this->addFlash('success', 'studentOptionsUpdatedFlashMessage');

            return $this->redirectToRoute('app_program_settings_students', ['id' => $program->getId()]);
        }

        return $this->render('program/member_options.html.twig', [
            'form' => $form,
            'program' => $program,
            'member' => $student,
            'backRoute' => 'app_program_settings_students',
        ]);
    }

    #[Route(path: '/programs/{id}/settings/teachers/{userId}/options', name: 'app_program_settings_teachers_options')]
    public function teacherOptionsForm(int $id, int $userId, Request $request, ProgramRepository $repository, UserRepository $userRepository, ProgramTeacherOptionRepository $teacherOptionRepository, EntityManagerInterface $entityManager): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $teacher = $userRepository->find($userId) ?? throw $this->createNotFoundException();

        if (!$program->getTeachers()->contains($teacher)) {
            throw $this->createNotFoundException();
        }

        $currentOptions = $teacherOptionRepository->findOptionsForTeacher($program, $teacher);
        $form = $this->createForm(MemberOptionsType::class, ['options' => $currentOptions], ['program' => $program]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $selectedOptions = $form->get('options')->getData();
            $selectedIds = array_map(static fn (Option $option): int => $option->getId(), $selectedOptions);
            $currentIds = array_map(static fn (Option $option): int => $option->getId(), $currentOptions);

            foreach ($teacherOptionRepository->findAllForProgramAndTeacher($program, $teacher) as $link) {
                if (!in_array($link->getOption()->getId(), $selectedIds, true)) {
                    $entityManager->remove($link);
                }
            }

            foreach ($selectedOptions as $option) {
                if (!in_array($option->getId(), $currentIds, true)) {
                    $entityManager->persist(new ProgramTeacherOption($program, $teacher, $option));
                }
            }

            $entityManager->flush();
            $this->addFlash('success', 'teacherOptionsUpdatedFlashMessage');

            return $this->redirectToRoute('app_program_settings_teachers', ['id' => $program->getId()]);
        }

        return $this->render('program/member_options.html.twig', [
            'form' => $form,
            'program' => $program,
            'member' => $teacher,
            'backRoute' => 'app_program_settings_teachers',
        ]);
    }

    #[Route(path: '/programs/{id}/settings/reports/new', name: 'app_program_settings_reports_new')]
    #[Route(path: '/programs/{id}/settings/reports/{reportId}/edit', name: 'app_program_settings_reports_edit')]
    public function reportForm(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, ProgramReportRepository $reportRepository, ?int $reportId = null): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $report = null !== $reportId ? $this->findReportOrNotFound($reportRepository, $program, $reportId) : null;
        $isEdit = null !== $report;

        $form = $this->createForm(ProgramReportType::class, $report, ['program' => $program]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'reportUpdatedFlashMessage' : 'reportCreatedFlashMessage');

            return $this->redirectToRoute('app_program_settings_reports', ['id' => $program->getId()]);
        }

        return $this->render('program/report_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'program' => $program,
        ]);
    }

    #[Route(path: '/programs/{id}/settings/reports/{reportId}/deactivate', name: 'app_program_settings_reports_deactivate', methods: ['POST'])]
    public function deactivateReport(int $id, int $reportId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, ProgramReportRepository $reportRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $report = $this->findReportOrNotFound($reportRepository, $program, $reportId);
        $this->assertValidToken('program_settings_deactivate', $request);

        $report->setInactiveDate(new \DateTimeImmutable());
        $report->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/programs/{id}/settings/reports/data', name: 'app_program_settings_reports_data')]
    public function reportsData(int $id, Request $request, ProgramRepository $repository, ProgramReportRepository $reportRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        [$draw, $start, $length, $search, $includeInactive] = $this->readActiveFilterableDataTableParams($request);

        $total = $reportRepository->countAllForProgram($program, null, $includeInactive);
        $filteredTotal = '' !== $search ? $reportRepository->countAllForProgram($program, $search, $includeInactive) : $total;
        $rows = $reportRepository->findPageForProgramOrderedByMostRecent($program, $start, $length, '' !== $search ? $search : null, $includeInactive);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (ProgramReport $report): array => [
                    'id' => $report->getId(),
                    'isInactive' => null !== $report->getInactiveDate(),
                    'title' => $report->getTitle(),
                    'day' => $report->getDay()->format('d/m/Y'),
                    'refereeName' => $this->userLabel($report->getReferee()),
                ],
                $rows,
            ),
        ]);
    }

    #[Route(path: '/programs/{id}/settings/reports/{reportId}/print', name: 'app_program_settings_reports_print')]
    public function printReport(int $id, int $reportId, ProgramRepository $repository, ProgramReportRepository $reportRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $report = $this->findReportOrNotFound($reportRepository, $program, $reportId);

        return $this->render('program/report_print.html.twig', [
            'program' => $program,
            'report' => $report,
        ]);
    }

    private function findSkillGroupOrNotFound(SkillGroupRepository $repository, Program $program, int $groupId): SkillGroup
    {
        $skillGroup = $repository->find($groupId) ?? throw $this->createNotFoundException();

        if ($skillGroup->getProgram()->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        return $skillGroup;
    }

    private function findSkillOrNotFound(SkillRepository $repository, SkillGroup $skillGroup, int $skillId): Skill
    {
        $skill = $repository->find($skillId) ?? throw $this->createNotFoundException();

        if ($skill->getSkillGroup()?->getId() !== $skillGroup->getId()) {
            throw $this->createNotFoundException();
        }

        return $skill;
    }

    private function findSkillLevelOrNotFound(SkillLevelRepository $repository, Program $program, int $levelId): SkillLevel
    {
        $skillLevel = $repository->find($levelId) ?? throw $this->createNotFoundException();

        if ($skillLevel->getProgram()?->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        return $skillLevel;
    }

    private function findReportOrNotFound(ProgramReportRepository $repository, Program $program, int $reportId): ProgramReport
    {
        $report = $repository->find($reportId) ?? throw $this->createNotFoundException();

        if ($report->getProgram()->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        return $report;
    }

    #[Route(path: '/programs/{id}/settings/financial/items/new-lesson', name: 'app_program_settings_financial_items_new_lesson')]
    #[Route(path: '/programs/{id}/settings/financial/items/new-student', name: 'app_program_settings_financial_items_new_student')]
    #[Route(path: '/programs/{id}/settings/financial/items/new-manual', name: 'app_program_settings_financial_items_new_manual')]
    #[Route(path: '/programs/{id}/settings/financial/items/{itemId}/edit', name: 'app_program_settings_financial_items_edit')]
    public function financialItemForm(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, ProgramFinancialItemRepository $financialItemRepository, LessonTypeRepository $lessonTypeRepository, ProgramFinancialCalculator $calculator, ?int $itemId = null): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $this->assertProgramFeatureEnabled($program->isFinancialManagementEnabled());
        $financialItem = null !== $itemId ? $this->findFinancialItemOrNotFound($financialItemRepository, $program, $itemId) : null;
        $isEdit = null !== $financialItem;

        if ($isEdit) {
            $source = $financialItem->getSource();
        } else {
            $source = match ($request->attributes->get('_route')) {
                'app_program_settings_financial_items_new_lesson' => FinancialItemSource::Lesson,
                'app_program_settings_financial_items_new_student' => FinancialItemSource::Student,
                'app_program_settings_financial_items_new_manual' => FinancialItemSource::Manual,
                default => throw $this->createNotFoundException(),
            };
        }

        $form = $this->createForm(ProgramFinancialItemType::class, $financialItem, ['program' => $program, 'source' => $source]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($form->getData());
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'financialItemUpdatedFlashMessage' : 'financialItemCreatedFlashMessage');

            return $this->redirectToRoute('app_program_settings_financial', ['id' => $program->getId()]);
        }

        return $this->render('program/financial_item_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'program' => $program,
            'source' => $source,
            'costsByLessonTypeId' => FinancialItemSource::Lesson === $source
                ? $calculator->getEffectiveCostMap($program, $lessonTypeRepository->findAllActiveOrderedByName())
                : [],
        ]);
    }

    #[Route(path: '/programs/{id}/settings/financial/items/{itemId}/remove', name: 'app_program_settings_financial_items_remove', methods: ['POST'])]
    public function removeFinancialItem(int $id, int $itemId, Request $request, ProgramRepository $repository, ProgramFinancialItemRepository $financialItemRepository, EntityManagerInterface $entityManager): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $this->assertProgramFeatureEnabled($program->isFinancialManagementEnabled());
        $financialItem = $this->findFinancialItemOrNotFound($financialItemRepository, $program, $itemId);

        if (!$this->isCsrfTokenValid('program_settings_financial_remove', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $entityManager->remove($financialItem);
        $entityManager->flush();

        $this->addFlash('success', 'financialItemRemovedFlashMessage');

        return $this->redirectToRoute('app_program_settings_financial', ['id' => $program->getId()]);
    }

    #[Route(path: '/programs/{id}/settings/financial/costs', name: 'app_program_settings_financial_costs', methods: ['POST'])]
    public function updateLessonTypeCosts(int $id, Request $request, ProgramRepository $repository, LessonTypeRepository $lessonTypeRepository, ProgramLessonTypeCostRepository $costRepository, EntityManagerInterface $entityManager): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $this->assertProgramFeatureEnabled($program->isFinancialManagementEnabled());

        if (!$this->isCsrfTokenValid('program_settings_financial_costs', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $submittedCosts = $request->request->all('costs');

        foreach ($lessonTypeRepository->findAllActiveOrderedByName() as $lessonType) {
            $raw = trim((string) ($submittedCosts[$lessonType->getId()] ?? ''));
            $existingOverride = $costRepository->findOneForProgramAndLessonType($program, $lessonType);

            if ('' === $raw || !is_numeric($raw) || $raw < 0) {
                if ('' === $raw && null !== $existingOverride) {
                    $entityManager->remove($existingOverride);
                }

                continue;
            }

            if (null !== $existingOverride) {
                $existingOverride->setCost($raw);
            } else {
                $entityManager->persist(new ProgramLessonTypeCost($program, $lessonType, $raw));
            }
        }

        $entityManager->flush();
        $this->addFlash('success', 'lessonTypeCostsUpdatedFlashMessage');

        return $this->redirectToRoute('app_program_settings_financial', ['id' => $program->getId()]);
    }

    private function findFinancialItemOrNotFound(ProgramFinancialItemRepository $repository, Program $program, int $itemId): ProgramFinancialItem
    {
        $financialItem = $repository->find($itemId) ?? throw $this->createNotFoundException();

        if ($financialItem->getProgram()->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        return $financialItem;
    }

    private function renderTab(int $id, ProgramRepository $repository, string $tab, ?\Closure $isEnabled = null): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        if (null !== $isEnabled) {
            $this->assertProgramFeatureEnabled($isEnabled($program));
        }

        return $this->render('program/settings.html.twig', [
            'program' => $program,
            'activeTab' => $tab,
        ]);
    }

    /**
     * @param Collection<int, User>          $members
     * @param array<int, list<Option>>|null  $optionsByMemberId When given, adds an "optionsLabel" field per row (only when the program has options at all)
     */
    private function membersData(Request $request, Collection $members, ?array $optionsByMemberId = null): JsonResponse
    {
        [$draw, $start, $length, $search] = $this->readDataTableParams($request);

        $filtered = [] === $search ? $members->toArray() : array_values(array_filter(
            $members->toArray(),
            static fn (User $user): bool => str_contains(strtolower($user->getDisplayName() ?? $user->getUsername()), $search)
                || str_contains(strtolower($user->getUsername()), $search),
        ));

        usort($filtered, static fn (User $a, User $b): int => ($a->getDisplayName() ?? $a->getUsername()) <=> ($b->getDisplayName() ?? $b->getUsername()));

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $members->count(),
            'recordsFiltered' => count($filtered),
            'data' => array_map(
                function (User $user) use ($optionsByMemberId): array {
                    $row = [
                        'id' => $user->getId(),
                        'fullName' => $user->getDisplayName() ?? $user->getUsername(),
                        'username' => $user->getUsername(),
                        'email' => $user->getEmail() ?? '—',
                    ];

                    if (null !== $optionsByMemberId) {
                        $names = array_map(static fn (Option $option): string => $option->getShortName(), $optionsByMemberId[$user->getId()] ?? []);
                        $row['optionsLabel'] = [] === $names ? '—' : implode(', ', $names);
                    }

                    return $row;
                },
                array_slice($filtered, $start, $length),
            ),
        ]);
    }

    /** @param Collection<int, User> $currentMembers */
    private function candidatesData(Request $request, Program $program, Collection $currentMembers, string $typeRole, UserRepository $userRepository): JsonResponse
    {
        [$draw, $start, $length, $search] = $this->readDataTableParams($request);

        $cohortLdapGroup = $program->getCohort()->getLdapGroup();

        if (null === $cohortLdapGroup) {
            return $this->json(['draw' => $draw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
        }

        $excludedIds = array_map(static fn (User $user): ?int => $user->getId(), $currentMembers->toArray());
        $requiredRoles = ['ROLE_'.strtoupper($cohortLdapGroup->getName()), $typeRole];

        $candidates = $userRepository->findActiveMatchingRoles($requiredRoles, $excludedIds, '' !== $search ? $search : null);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => count($candidates),
            'recordsFiltered' => count($candidates),
            'data' => array_map(
                fn (User $user): array => [
                    'id' => $user->getId(),
                    'fullName' => $user->getDisplayName() ?? $user->getUsername(),
                    'username' => $user->getUsername(),
                    'email' => $user->getEmail() ?? '—',
                ],
                array_slice($candidates, $start, $length),
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
        $search = strtolower(trim((string) ($request->query->all('search')['value'] ?? '')));

        return [$draw, $start, $length, $search];
    }

    /** @return array{0: int, 1: int, 2: int, 3: string, 4: bool} */
    private function readActiveFilterableDataTableParams(Request $request): array
    {
        $draw = $request->query->getInt('draw', 1);
        $start = max(0, $request->query->getInt('start', 0));
        $length = $request->query->getInt('length', 10);
        $length = $length > 0 ? min($length, 50) : 10;
        $search = trim((string) ($request->query->all('search')['value'] ?? ''));
        $includeInactive = $request->query->getBoolean('includeInactive');

        return [$draw, $start, $length, $search, $includeInactive];
    }

    private function findOrNotFound(int $id, ProgramRepository $repository): Program
    {
        return $repository->find($id) ?? throw $this->createNotFoundException();
    }

    private function assertValidToken(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
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

    private function stampAuditFields(object $entity, bool $isEdit): void
    {
        if ($isEdit) {
            $entity->setLastUpdatedBy($this->currentUser());
            $entity->setLastUpdatedDate(new \DateTimeImmutable());
        } else {
            $entity->setCreatedBy($this->currentUser());
        }
    }
}
