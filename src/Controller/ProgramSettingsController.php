<?php

namespace App\Controller;

use App\Entity\LessonSession;
use App\Entity\Program;
use App\Entity\User;
use App\Form\LessonSessionType;
use App\Repository\LessonSessionRepository;
use App\Repository\ProgramRepository;
use App\Repository\UserRepository;
use App\Service\LessonSessionEventFormatter;
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
        return $this->renderTab($id, $repository, 'timetable');
    }

    #[Route(path: '/programs/{id}/settings/students/data', name: 'app_program_settings_students_data')]
    public function studentsData(int $id, Request $request, ProgramRepository $repository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);

        return $this->membersData($request, $program->getStudents());
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
    public function removeStudent(int $id, int $userId, Request $request, ProgramRepository $repository, UserRepository $userRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $user = $userRepository->find($userId) ?? throw $this->createNotFoundException();
        $this->assertValidToken('program_settings_remove', $request);

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

    #[Route(path: '/programs/{id}/settings/timetable/feed', name: 'app_program_settings_timetable_feed')]
    public function timetableFeed(int $id, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository, LessonSessionEventFormatter $eventFormatter): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
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
        $lessonSession = null !== $sessionId ? $this->findLessonSessionOrNotFound($lessonSessionRepository, $program, $sessionId) : null;
        $isEdit = null !== $lessonSession;

        if (!$isEdit) {
            // Pre-fills day/startHour/endHour from the calendar's "select" query params (the
            // Stimulus controller navigates here with them after a click-and-drag selection).
            $lessonSession = new LessonSession('', $program);
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

    private function renderTab(int $id, ProgramRepository $repository, string $tab): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        return $this->render('program/settings.html.twig', [
            'program' => $program,
            'activeTab' => $tab,
        ]);
    }

    /** @param Collection<int, User> $members */
    private function membersData(Request $request, Collection $members): JsonResponse
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
                fn (User $user): array => [
                    'id' => $user->getId(),
                    'fullName' => $user->getDisplayName() ?? $user->getUsername(),
                    'username' => $user->getUsername(),
                    'email' => $user->getEmail() ?? '—',
                ],
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
}
