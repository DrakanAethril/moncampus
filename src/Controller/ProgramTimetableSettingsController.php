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
    public function timetableTab(int $id, Request $request, ProgramRepository $repository): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        return $this->render('program/timetable_settings.html.twig', [
            'program' => $program,
            'activeTab' => 'timetable',
            // Set after creating/editing a session from the agenda (see lessonSessionForm()
            // below) so the calendar reopens on that session's week instead of always jumping
            // back to the current one.
            'focus' => $request->query->get('focus'),
        ]);
    }

    #[Route(path: '/programs/{id}/settings/topics', name: 'app_program_timetable_settings_topics')]
    public function topicsTab(int $id, ProgramRepository $repository, TopicRepository $topicRepository, LessonSessionRepository $lessonSessionRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        return $this->render('program/timetable_settings.html.twig', [
            'program' => $program,
            'activeTab' => 'topics',
            'topics' => $topicRepository->findAllForProgramOrderedByOption($program),
            'plannedHoursByTopicId' => $lessonSessionRepository->findHoursByTopicForProgram($program),
        ]);
    }

    #[Route(path: '/programs/{id}/settings/topic-groups', name: 'app_program_timetable_settings_topic_groups')]
    public function topicGroupsTab(int $id, ProgramRepository $repository, TopicGroupRepository $topicGroupRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        return $this->render('program/timetable_settings.html.twig', [
            'program' => $program,
            'activeTab' => 'topic_groups',
            'topicGroups' => $topicGroupRepository->findAllActiveForProgram($program),
        ]);
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

    #[Route(path: '/programs/{id}/settings/timetable/sessions/topics-search', name: 'app_program_timetable_settings_sessions_topics_search')]
    public function topicsSearch(int $id, Request $request, ProgramRepository $repository, TopicRepository $topicRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $limit = 20;
        $topics = $topicRepository->searchActiveForProgram($program, $request->query->get('q'), $limit);

        return $this->json([
            'results' => array_map(static fn (Topic $topic): array => ['id' => $topic->getId(), 'text' => $topic->getName()], $topics),
            'pagination' => ['more' => \count($topics) === $limit],
        ]);
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
            $startDate = null;
            $endDate = null;

            if (null !== $start) {
                $startDate = new \DateTimeImmutable($start);
                $lessonSession->setDay($startDate);
                $lessonSession->setStartHour($startDate);
            }

            if (null !== $end) {
                $endDate = new \DateTimeImmutable($end);
                $lessonSession->setEndHour($endDate);
            }

            // Prefills length from the selected slot's duration, rounded half up to the nearest
            // half hour (e.g. 1h15 -> 1.5H) - still a plain editable default, not a live binding:
            // length stays manually entered/persisted, see the entity's own docblock.
            if (null !== $startDate && null !== $endDate) {
                $hours = ($endDate->getTimestamp() - $startDate->getTimestamp()) / 3600;
                $roundedHours = round($hours * 2, 0, PHP_ROUND_HALF_UP) / 2;
                $lessonSession->setLength(number_format($roundedHours, 2, '.', ''));
            }
        }

        $form = $this->createForm(LessonSessionType::class, $lessonSession, ['program' => $program]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var LessonSession $savedSession */
            $savedSession = $form->getData();
            $entityManager->persist($savedSession);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'lessonSessionUpdatedFlashMessage' : 'lessonSessionCreatedFlashMessage');

            // Reopens the agenda on the edited session's own week instead of always jumping back
            // to the current one - see timetableTab()'s "focus" handling above.
            return $this->redirectToRoute('app_program_timetable_settings', [
                'id' => $program->getId(),
                'focus' => $savedSession->getDay()?->format('Y-m-d'),
            ]);
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

    // Plain POST + redirect (not ajax/JSON, unlike App\Controller\ProgramTimetableSettingsController's
    // other deactivate actions) - same reasoning as removeLessonSession() above: the Topics tab
    // is now a single server-rendered page (see topicsTab()), not an ajax-paginated DataTable, so
    // there's no client-side table to reload in place after the action.
    #[Route(path: '/programs/{id}/settings/topics/{topicId}/deactivate', name: 'app_program_timetable_settings_topics_deactivate', methods: ['POST'])]
    public function deactivateTopic(int $id, int $topicId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, TopicRepository $topicRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $topic = $this->findTopicOrNotFound($topicRepository, $program, $topicId);

        if (!$this->isCsrfTokenValid('program_settings_topics_deactivate', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $topic->setInactiveDate(new \DateTimeImmutable());
        $topic->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        $this->addFlash('success', 'topicDeactivatedFlashMessage');

        return $this->redirectToRoute('app_program_timetable_settings_topics', ['id' => $program->getId()]);
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

    // Plain POST + redirect (not ajax/JSON) - same reasoning as deactivateTopic() above: the
    // Groupes de matières tab is now a single server-rendered page (see topicGroupsTab()), not an
    // ajax-paginated DataTable, so there's no client-side table to reload in place after the
    // action.
    #[Route(path: '/programs/{id}/settings/topic-groups/{topicGroupId}/deactivate', name: 'app_program_timetable_settings_topic_groups_deactivate', methods: ['POST'])]
    public function deactivateTopicGroup(int $id, int $topicGroupId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, TopicGroupRepository $topicGroupRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $topicGroup = $this->findTopicGroupOrNotFound($topicGroupRepository, $program, $topicGroupId);

        if (!$this->isCsrfTokenValid('program_settings_topic_groups_deactivate', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $topicGroup->setInactiveDate(new \DateTimeImmutable());
        $topicGroup->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        $this->addFlash('success', 'topicGroupDeactivatedFlashMessage');

        return $this->redirectToRoute('app_program_timetable_settings_topic_groups', ['id' => $program->getId()]);
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
