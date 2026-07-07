<?php

namespace App\Controller;

use App\Entity\LessonSession;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\ProgramFinancialItem;
use App\Entity\ProgramLessonTypeCost;
use App\Entity\ProgramReport;
use App\Entity\ProgramStudentOption;
use App\Entity\Skill;
use App\Entity\Topic;
use App\Entity\User;
use App\Enum\FinancialItemSource;
use App\Form\LessonSessionType;
use App\Form\ProgramFinancialItemType;
use App\Form\ProgramReportType;
use App\Form\SkillType;
use App\Form\StudentOptionsType;
use App\Form\TopicType;
use App\Repository\LessonSessionRepository;
use App\Repository\LessonTypeRepository;
use App\Repository\ProgramFinancialItemRepository;
use App\Repository\ProgramLessonTypeCostRepository;
use App\Repository\ProgramReportRepository;
use App\Repository\ProgramRepository;
use App\Repository\ProgramStudentOptionRepository;
use App\Repository\SkillRepository;
use App\Repository\TopicRepository;
use App\Repository\UserRepository;
use App\Service\LessonSessionEventFormatter;
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

// The "Paramétrage" page reached via the Section > Année scolaire > Classe nav menu - reuses
// the same tab shell pattern as SettingsStructureController (each tab its own route, shared
// settings/structure.html.twig-style shell, only the active tab's content/data ever loads).
// Staff/admin only, same as the rest of the structure management area.
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

    #[Route(path: '/programs/{id}/settings/timetable', name: 'app_program_settings_timetable')]
    public function timetableTab(int $id, ProgramRepository $repository): Response
    {
        return $this->renderTab($id, $repository, 'timetable', static fn (Program $program): bool => $program->isTimetableManagementEnabled());
    }

    #[Route(path: '/programs/{id}/settings/topics', name: 'app_program_settings_topics')]
    public function topicsTab(int $id, ProgramRepository $repository): Response
    {
        return $this->renderTab($id, $repository, 'topics', static fn (Program $program): bool => $program->isTopicSkillManagementEnabled());
    }

    #[Route(path: '/programs/{id}/settings/skills', name: 'app_program_settings_skills')]
    public function skillsTab(int $id, ProgramRepository $repository): Response
    {
        return $this->renderTab($id, $repository, 'skills', static fn (Program $program): bool => $program->isTopicSkillManagementEnabled());
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
    public function teachersData(int $id, Request $request, ProgramRepository $repository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);

        return $this->membersData($request, $program->getTeachers());
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
    public function removeTeacher(int $id, int $userId, Request $request, ProgramRepository $repository, UserRepository $userRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $user = $userRepository->find($userId) ?? throw $this->createNotFoundException();
        $this->assertValidToken('program_settings_remove', $request);

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
        $form = $this->createForm(StudentOptionsType::class, ['options' => $currentOptions], ['program' => $program]);
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

        return $this->render('program/student_options.html.twig', [
            'form' => $form,
            'program' => $program,
            'student' => $student,
        ]);
    }

    #[Route(path: '/programs/{id}/settings/timetable/feed', name: 'app_program_settings_timetable_feed')]
    public function timetableFeed(int $id, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository, LessonSessionEventFormatter $eventFormatter): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $this->assertProgramFeatureEnabled($program->isTimetableManagementEnabled());
        $sessions = $lessonSessionRepository->findForProgram($program);

        return $this->json(array_map(
            static fn (LessonSession $session): array => $eventFormatter->format($session, editable: true),
            $sessions,
        ));
    }

    #[Route(path: '/programs/{id}/settings/timetable/sessions/new', name: 'app_program_settings_timetable_sessions_new')]
    #[Route(path: '/programs/{id}/settings/timetable/sessions/{sessionId}/edit', name: 'app_program_settings_timetable_sessions_edit')]
    public function lessonSessionForm(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository, ?int $sessionId = null): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $this->assertProgramFeatureEnabled($program->isTimetableManagementEnabled());
        $lessonSession = null !== $sessionId ? $this->findLessonSessionOrNotFound($lessonSessionRepository, $program, $sessionId) : null;
        $isEdit = null !== $lessonSession;

        if (!$isEdit) {
            // Pre-fills day/startHour/endHour from the calendar's "select" query params (the
            // Stimulus controller navigates here with them after a click-and-drag selection).
            $lessonSession = new LessonSession($program);
            $start = $request->query->get('start');
            $end = $request->query->get('end');

            if (null !== $start) {
                $startDate = new \DateTimeImmutable($start);
                $lessonSession->setDay($startDate);
                $lessonSession->setStartHour($startDate);
            }

            if (null !== $end) {
                $lessonSession->setEndHour(new \DateTimeImmutable($end));
            }
        }

        $form = $this->createForm(LessonSessionType::class, $lessonSession, ['program' => $program]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($form->getData());
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'lessonSessionUpdatedFlashMessage' : 'lessonSessionCreatedFlashMessage');

            return $this->redirectToRoute('app_program_settings_timetable', ['id' => $program->getId()]);
        }

        return $this->render('program/lesson_session_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'program' => $program,
        ]);
    }

    #[Route(path: '/programs/{id}/settings/timetable/sessions/{sessionId}/move', name: 'app_program_settings_timetable_sessions_move', methods: ['POST'])]
    public function moveLessonSession(int $id, int $sessionId, Request $request, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $this->assertProgramFeatureEnabled($program->isTimetableManagementEnabled());
        $lessonSession = $this->findLessonSessionOrNotFound($lessonSessionRepository, $program, $sessionId);
        $this->assertValidToken('program_settings_timetable_move', $request);

        $payload = json_decode($request->getContent(), true);
        $start = new \DateTimeImmutable($payload['start'] ?? throw $this->createAccessDeniedException());
        $end = new \DateTimeImmutable($payload['end'] ?? throw $this->createAccessDeniedException());

        $lessonSession->setDay($start);
        $lessonSession->setStartHour($start);
        $lessonSession->setEndHour($end);
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/programs/{id}/settings/timetable/sessions/{sessionId}/remove', name: 'app_program_settings_timetable_sessions_remove', methods: ['POST'])]
    public function removeLessonSession(int $id, int $sessionId, Request $request, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository, EntityManagerInterface $entityManager): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $this->assertProgramFeatureEnabled($program->isTimetableManagementEnabled());
        $lessonSession = $this->findLessonSessionOrNotFound($lessonSessionRepository, $program, $sessionId);

        if (!$this->isCsrfTokenValid('program_settings_timetable_remove', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $entityManager->remove($lessonSession);
        $entityManager->flush();

        $this->addFlash('success', 'lessonSessionRemovedFlashMessage');

        return $this->redirectToRoute('app_program_settings_timetable', ['id' => $program->getId()]);
    }

    private function findLessonSessionOrNotFound(LessonSessionRepository $repository, Program $program, int $sessionId): LessonSession
    {
        $lessonSession = $repository->find($sessionId) ?? throw $this->createNotFoundException();

        if ($lessonSession->getProgram()->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        return $lessonSession;
    }

    #[Route(path: '/programs/{id}/settings/topics/new', name: 'app_program_settings_topics_new')]
    #[Route(path: '/programs/{id}/settings/topics/{topicId}/edit', name: 'app_program_settings_topics_edit')]
    public function topicForm(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, TopicRepository $topicRepository, ?int $topicId = null): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $this->assertProgramFeatureEnabled($program->isTopicSkillManagementEnabled());
        $topic = null !== $topicId ? $this->findTopicOrNotFound($topicRepository, $program, $topicId) : null;
        $isEdit = null !== $topic;

        $form = $this->createForm(TopicType::class, $topic, ['program' => $program]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'topicUpdatedFlashMessage' : 'topicCreatedFlashMessage');

            return $this->redirectToRoute('app_program_settings_topics', ['id' => $program->getId()]);
        }

        return $this->render('program/topic_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'program' => $program,
        ]);
    }

    #[Route(path: '/programs/{id}/settings/topics/{topicId}/deactivate', name: 'app_program_settings_topics_deactivate', methods: ['POST'])]
    public function deactivateTopic(int $id, int $topicId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, TopicRepository $topicRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $this->assertProgramFeatureEnabled($program->isTopicSkillManagementEnabled());
        $topic = $this->findTopicOrNotFound($topicRepository, $program, $topicId);
        $this->assertValidToken('program_settings_deactivate', $request);

        $topic->setInactiveDate(new \DateTimeImmutable());
        $topic->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/programs/{id}/settings/topics/data', name: 'app_program_settings_topics_data')]
    public function topicsData(int $id, Request $request, ProgramRepository $repository, TopicRepository $topicRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $this->assertProgramFeatureEnabled($program->isTopicSkillManagementEnabled());
        [$draw, $start, $length, $search, $includeInactive] = $this->readActiveFilterableDataTableParams($request);

        $total = $topicRepository->countAllForProgram($program, null, $includeInactive);
        $filteredTotal = '' !== $search ? $topicRepository->countAllForProgram($program, $search, $includeInactive) : $total;
        $rows = $topicRepository->findPageForProgramOrderedByMostRecent($program, $start, $length, '' !== $search ? $search : null, $includeInactive);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (Topic $topic): array => [
                    'id' => $topic->getId(),
                    'isInactive' => null !== $topic->getInactiveDate(),
                    'name' => $topic->getName(),
                    'targetCmHours' => $topic->getTargetCmHours(),
                    'targetTdHours' => $topic->getTargetTdHours(),
                    'targetTpHours' => $topic->getTargetTpHours(),
                    'totalTargetHours' => $topic->getTotalTargetHours(),
                    'teacherName' => $this->userLabel($topic->getTeacher()),
                    'creationDate' => $topic->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $topic->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($topic->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($topic->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($topic->getLastUpdatedBy()),
                    'lastUpdatedDate' => $topic->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }

    #[Route(path: '/programs/{id}/settings/skills/new', name: 'app_program_settings_skills_new')]
    #[Route(path: '/programs/{id}/settings/skills/{skillId}/edit', name: 'app_program_settings_skills_edit')]
    public function skillForm(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, SkillRepository $skillRepository, ?int $skillId = null): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $this->assertProgramFeatureEnabled($program->isTopicSkillManagementEnabled());
        $skill = null !== $skillId ? $this->findSkillOrNotFound($skillRepository, $program, $skillId) : null;
        $isEdit = null !== $skill;

        $form = $this->createForm(SkillType::class, $skill, ['program' => $program]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'skillUpdatedFlashMessage' : 'skillCreatedFlashMessage');

            return $this->redirectToRoute('app_program_settings_skills', ['id' => $program->getId()]);
        }

        return $this->render('program/skill_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'program' => $program,
        ]);
    }

    #[Route(path: '/programs/{id}/settings/skills/{skillId}/deactivate', name: 'app_program_settings_skills_deactivate', methods: ['POST'])]
    public function deactivateSkill(int $id, int $skillId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, SkillRepository $skillRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $this->assertProgramFeatureEnabled($program->isTopicSkillManagementEnabled());
        $skill = $this->findSkillOrNotFound($skillRepository, $program, $skillId);
        $this->assertValidToken('program_settings_deactivate', $request);

        $skill->setInactiveDate(new \DateTimeImmutable());
        $skill->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/programs/{id}/settings/skills/data', name: 'app_program_settings_skills_data')]
    public function skillsData(int $id, Request $request, ProgramRepository $repository, SkillRepository $skillRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $this->assertProgramFeatureEnabled($program->isTopicSkillManagementEnabled());
        [$draw, $start, $length, $search, $includeInactive] = $this->readActiveFilterableDataTableParams($request);

        $total = $skillRepository->countAllForProgram($program, null, $includeInactive);
        $filteredTotal = '' !== $search ? $skillRepository->countAllForProgram($program, $search, $includeInactive) : $total;
        $rows = $skillRepository->findPageForProgramOrderedByMostRecent($program, $start, $length, '' !== $search ? $search : null, $includeInactive);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (Skill $skill): array => [
                    'id' => $skill->getId(),
                    'isInactive' => null !== $skill->getInactiveDate(),
                    'name' => $skill->getName(),
                    'shortName' => $skill->getShortName() ?? '—',
                    'volume' => $skill->getVolume() ?? '—',
                    'period' => $skill->getPeriod() ?? '—',
                    'teacherName' => $this->userLabel($skill->getTeacher()),
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

    private function findTopicOrNotFound(TopicRepository $repository, Program $program, int $topicId): Topic
    {
        $topic = $repository->find($topicId) ?? throw $this->createNotFoundException();

        if ($topic->getProgram()->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        return $topic;
    }

    private function findSkillOrNotFound(SkillRepository $repository, Program $program, int $skillId): Skill
    {
        $skill = $repository->find($skillId) ?? throw $this->createNotFoundException();

        if ($skill->getProgram()->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        return $skill;
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
     * @param array<int, list<Option>>|null  $optionsByStudentId When given, adds an "optionsLabel" field per row (students tab only)
     */
    private function membersData(Request $request, Collection $members, ?array $optionsByStudentId = null): JsonResponse
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
                function (User $user) use ($optionsByStudentId): array {
                    $row = [
                        'id' => $user->getId(),
                        'fullName' => $user->getDisplayName() ?? $user->getUsername(),
                        'username' => $user->getUsername(),
                        'email' => $user->getEmail() ?? '—',
                    ];

                    if (null !== $optionsByStudentId) {
                        $names = array_map(static fn (Option $option): string => $option->getShortName(), $optionsByStudentId[$user->getId()] ?? []);
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
