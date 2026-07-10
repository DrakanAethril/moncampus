<?php

namespace App\Controller;

use App\Entity\LessonSession;
use App\Entity\Program;
use App\Entity\Topic;
use App\Entity\TopicGroup;
use App\Entity\User;
use App\Form\LessonSessionType;
use App\Form\TopicGroupType;
use App\Form\TopicType;
use App\Repository\LessonSessionRepository;
use App\Repository\ProgramRepository;
use App\Repository\TopicGroupRepository;
use App\Repository\TopicRepository;
use App\Service\LessonSessionEventFormatter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// The "Emploi du temps" page reached via the Paramétrage submenu - sibling of
// ProgramSettingsController (Programme) and ProgramInternshipController (Livret de l'alternant),
// see templates/layout/app.html.twig. Split out of ProgramSettingsController so each of the
// three groups gets its own tab shell instead of one dense 8-tab page. Every tab here is gated
// by the same single toggle (isTimetableManagementEnabled()), since Topics/TopicGroups only
// make sense once the timetable itself is in use.
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class ProgramTimetableSettingsController extends AbstractController
{
    use ProgramFeatureGuardTrait;

    #[Route(path: '/programs/{id}/settings/timetable', name: 'app_program_timetable_settings')]
    public function timetableTab(int $id, ProgramRepository $repository): Response
    {
        return $this->renderTab($id, $repository, 'timetable');
    }

    #[Route(path: '/programs/{id}/settings/topics', name: 'app_program_timetable_settings_topics')]
    public function topicsTab(int $id, ProgramRepository $repository): Response
    {
        return $this->renderTab($id, $repository, 'topics');
    }

    #[Route(path: '/programs/{id}/settings/topic-groups', name: 'app_program_timetable_settings_topic_groups')]
    public function topicGroupsTab(int $id, ProgramRepository $repository): Response
    {
        return $this->renderTab($id, $repository, 'topic_groups');
    }

    #[Route(path: '/programs/{id}/settings/timetable/team', name: 'app_program_timetable_settings_team')]
    public function teamTab(int $id, ProgramRepository $repository, TopicRepository $topicRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        // Read-only: the teaching team roster is derived from the program's existing Topics
        // (discipline -> teacher) rather than a separate entity - see the entity docblocks for
        // why this isn't duplicated here.
        $topicsByTeacher = [];
        foreach ($topicRepository->findAllActiveForProgram($program) as $topic) {
            $teacher = $topic->getTeacher();
            $key = $teacher?->getId() ?? 0;

            if (!isset($topicsByTeacher[$key])) {
                $topicsByTeacher[$key] = ['teacher' => $teacher, 'topics' => []];
            }

            $topicsByTeacher[$key]['topics'][] = $topic;
        }

        return $this->render('program/timetable_settings.html.twig', [
            'program' => $program,
            'activeTab' => 'team',
            'topicsByTeacher' => $topicsByTeacher,
        ]);
    }

    #[Route(path: '/programs/{id}/settings/timetable/feed', name: 'app_program_timetable_settings_feed')]
    public function timetableFeed(int $id, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository, LessonSessionEventFormatter $eventFormatter): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $sessions = $lessonSessionRepository->findForProgram($program);

        return $this->json(array_map(
            static fn (LessonSession $session): array => $eventFormatter->format($session, editable: true),
            $sessions,
        ));
    }

    #[Route(path: '/programs/{id}/settings/timetable/sessions/new', name: 'app_program_timetable_settings_sessions_new')]
    #[Route(path: '/programs/{id}/settings/timetable/sessions/{sessionId}/edit', name: 'app_program_timetable_settings_sessions_edit')]
    public function lessonSessionForm(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository, ?int $sessionId = null): Response
    {
        $program = $this->findOrNotFound($id, $repository);
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

            return $this->redirectToRoute('app_program_timetable_settings', ['id' => $program->getId()]);
        }

        return $this->render('program/lesson_session_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'program' => $program,
        ]);
    }

    #[Route(path: '/programs/{id}/settings/timetable/sessions/{sessionId}/move', name: 'app_program_timetable_settings_sessions_move', methods: ['POST'])]
    public function moveLessonSession(int $id, int $sessionId, Request $request, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
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

    #[Route(path: '/programs/{id}/settings/timetable/sessions/{sessionId}/remove', name: 'app_program_timetable_settings_sessions_remove', methods: ['POST'])]
    public function removeLessonSession(int $id, int $sessionId, Request $request, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository, EntityManagerInterface $entityManager): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $lessonSession = $this->findLessonSessionOrNotFound($lessonSessionRepository, $program, $sessionId);

        if (!$this->isCsrfTokenValid('program_settings_timetable_remove', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $entityManager->remove($lessonSession);
        $entityManager->flush();

        $this->addFlash('success', 'lessonSessionRemovedFlashMessage');

        return $this->redirectToRoute('app_program_timetable_settings', ['id' => $program->getId()]);
    }

    private function findLessonSessionOrNotFound(LessonSessionRepository $repository, Program $program, int $sessionId): LessonSession
    {
        $lessonSession = $repository->find($sessionId) ?? throw $this->createNotFoundException();

        if ($lessonSession->getProgram()->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        return $lessonSession;
    }

    #[Route(path: '/programs/{id}/settings/topics/new', name: 'app_program_timetable_settings_topics_new')]
    #[Route(path: '/programs/{id}/settings/topics/{topicId}/edit', name: 'app_program_timetable_settings_topics_edit')]
    public function topicForm(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, TopicRepository $topicRepository, ?int $topicId = null): Response
    {
        $program = $this->findOrNotFound($id, $repository);
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

            return $this->redirectToRoute('app_program_timetable_settings_topics', ['id' => $program->getId()]);
        }

        return $this->render('program/topic_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'program' => $program,
        ]);
    }

    #[Route(path: '/programs/{id}/settings/topics/{topicId}/deactivate', name: 'app_program_timetable_settings_topics_deactivate', methods: ['POST'])]
    public function deactivateTopic(int $id, int $topicId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, TopicRepository $topicRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $topic = $this->findTopicOrNotFound($topicRepository, $program, $topicId);
        $this->assertValidToken('program_settings_deactivate', $request);

        $topic->setInactiveDate(new \DateTimeImmutable());
        $topic->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/programs/{id}/settings/topics/data', name: 'app_program_timetable_settings_topics_data')]
    public function topicsData(int $id, Request $request, ProgramRepository $repository, TopicRepository $topicRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
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
                    'topicGroupName' => $topic->getTopicGroup()?->getName() ?? '—',
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

    #[Route(path: '/programs/{id}/settings/topic-groups/new', name: 'app_program_timetable_settings_topic_groups_new')]
    #[Route(path: '/programs/{id}/settings/topic-groups/{topicGroupId}/edit', name: 'app_program_timetable_settings_topic_groups_edit')]
    public function topicGroupForm(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, TopicGroupRepository $topicGroupRepository, ?int $topicGroupId = null): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $topicGroup = null !== $topicGroupId ? $this->findTopicGroupOrNotFound($topicGroupRepository, $program, $topicGroupId) : null;
        $isEdit = null !== $topicGroup;

        $form = $this->createForm(TopicGroupType::class, $topicGroup, ['program' => $program]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'topicGroupUpdatedFlashMessage' : 'topicGroupCreatedFlashMessage');

            return $this->redirectToRoute('app_program_timetable_settings_topic_groups', ['id' => $program->getId()]);
        }

        return $this->render('program/topic_group_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'program' => $program,
        ]);
    }

    #[Route(path: '/programs/{id}/settings/topic-groups/{topicGroupId}/deactivate', name: 'app_program_timetable_settings_topic_groups_deactivate', methods: ['POST'])]
    public function deactivateTopicGroup(int $id, int $topicGroupId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, TopicGroupRepository $topicGroupRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $topicGroup = $this->findTopicGroupOrNotFound($topicGroupRepository, $program, $topicGroupId);
        $this->assertValidToken('program_settings_deactivate', $request);

        $topicGroup->setInactiveDate(new \DateTimeImmutable());
        $topicGroup->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/programs/{id}/settings/topic-groups/data', name: 'app_program_timetable_settings_topic_groups_data')]
    public function topicGroupsData(int $id, Request $request, ProgramRepository $repository, TopicGroupRepository $topicGroupRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        [$draw, $start, $length, $search, $includeInactive] = $this->readActiveFilterableDataTableParams($request);

        $total = $topicGroupRepository->countAllForProgram($program, null, $includeInactive);
        $filteredTotal = '' !== $search ? $topicGroupRepository->countAllForProgram($program, $search, $includeInactive) : $total;
        $rows = $topicGroupRepository->findPageForProgramOrderedByMostRecent($program, $start, $length, '' !== $search ? $search : null, $includeInactive);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (TopicGroup $topicGroup): array => [
                    'id' => $topicGroup->getId(),
                    'isInactive' => null !== $topicGroup->getInactiveDate(),
                    'name' => $topicGroup->getName(),
                    'creationDate' => $topicGroup->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $topicGroup->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($topicGroup->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($topicGroup->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($topicGroup->getLastUpdatedBy()),
                    'lastUpdatedDate' => $topicGroup->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
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

    private function findTopicGroupOrNotFound(TopicGroupRepository $repository, Program $program, int $topicGroupId): TopicGroup
    {
        $topicGroup = $repository->find($topicGroupId) ?? throw $this->createNotFoundException();

        if ($topicGroup->getProgram()->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        return $topicGroup;
    }

    private function renderTab(int $id, ProgramRepository $repository, string $tab): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        return $this->render('program/timetable_settings.html.twig', [
            'program' => $program,
            'activeTab' => $tab,
        ]);
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
        $program = $repository->find($id) ?? throw $this->createNotFoundException();
        $this->assertProgramFeatureEnabled($program->isTimetableManagementEnabled());

        return $program;
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
