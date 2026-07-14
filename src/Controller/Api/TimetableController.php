<?php

namespace App\Controller\Api;

use App\Entity\LessonSession;
use App\Entity\User;
use App\Repository\LessonSessionRepository;
use App\Repository\ProgramRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only mobile timetable feed - same role-branching (student: their one active Program;
 * teacher: every session they teach across all their Programs) as HomeController's "Ma journée"/
 * "upcoming sessions" widgets, just date-ranged by query params instead of hardcoded to the next
 * 7 days. A user who is neither (pure staff/admin) has no personal timetable, same as on the web.
 */
#[Route(path: '/api/timetable', name: 'api_timetable', methods: ['GET'])]
class TimetableController extends AbstractController
{
    public function __invoke(Request $request, ProgramRepository $programRepository, LessonSessionRepository $lessonSessionRepository): JsonResponse
    {
        $user = $this->currentUser();
        [$from, $to] = $this->dateRange($request);

        $sessions = match (true) {
            $this->isGranted('ROLE_STUDENT') => $this->sessionsForStudent($user, $from, $to, $programRepository, $lessonSessionRepository),
            $this->isGranted('ROLE_TEACHER') => $lessonSessionRepository->findUpcomingForTeacher($user, $from, $to),
            default => [],
        };

        return $this->json([
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'sessions' => array_map($this->formatSession(...), $sessions),
        ]);
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} */
    private function dateRange(Request $request): array
    {
        $fromParam = $request->query->get('from');
        $toParam = $request->query->get('to');

        if (null !== $fromParam && null !== $toParam) {
            try {
                return [new \DateTimeImmutable($fromParam), new \DateTimeImmutable($toParam)];
            } catch (\Exception) {
                // Falls through to the default week below on an unparseable date.
            }
        }

        $monday = new \DateTimeImmutable('monday this week');

        return [$monday, $monday->modify('+6 days')];
    }

    /** @return list<LessonSession> */
    private function sessionsForStudent(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to, ProgramRepository $programRepository, LessonSessionRepository $lessonSessionRepository): array
    {
        $program = $programRepository->findActiveForStudent($user);

        return null !== $program ? $lessonSessionRepository->findForProgramBetween($program, $from, $to) : [];
    }

    /** @return array{id: int, title: string, day: string, startTime: string, endTime: string, teacher: string|null, room: string|null, color: string, program: string} */
    private function formatSession(LessonSession $session): array
    {
        return [
            'id' => $session->getId(),
            'title' => $session->getDisplayName(),
            'day' => $session->getDay()->format('Y-m-d'),
            'startTime' => $session->getStartHour()->format('H:i'),
            'endTime' => $session->getEndHour()->format('H:i'),
            'teacher' => null !== $session->getTeacher() ? ($session->getTeacher()->getDisplayName() ?? $session->getTeacher()->getUsername()) : null,
            'room' => $session->getClassRoom()?->getName(),
            'color' => $this->color($session),
            'program' => $session->getProgram()->getShortName(),
        ];
    }

    // Same "a single Option makes the color unambiguous" rule as LessonSessionEventFormatter
    // (web FullCalendar feed) - kept independent rather than shared since that one returns a CSS
    // var() fallback the mobile client can't resolve.
    private function color(LessonSession $session): string
    {
        $options = $session->getOptions()->toArray();

        return 1 === \count($options) ? $options[0]->getColor() : '#1B6BA8';
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
