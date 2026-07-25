<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\LessonSessionRepository;
use App\Service\LessonSessionEventFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// A teacher's personal weekly timetable, aggregating their LessonSessions across every Program
// they teach in - unlike App\Controller\ProgramController::timetable(), which is one Program's
// own calendar. Deliberately ROLE_TEACHER-only (not staff/admin too, unlike
// SequenceLibraryController): staff already see every Program's calendar via its own
// settings/timetable tab, and this personal view only makes sense for someone who actually
// teaches sessions.
#[IsGranted('ROLE_TEACHER')]
class TeacherTimetableController extends AbstractController
{
    #[Route(path: '/timetable', name: 'app_teacher_timetable')]
    public function index(): Response
    {
        return $this->render('teacher/timetable.html.twig');
    }

    #[Route(path: '/timetable/feed', name: 'app_teacher_timetable_feed')]
    public function feed(Request $request, LessonSessionRepository $repository, LessonSessionEventFormatter $eventFormatter): JsonResponse
    {
        // FullCalendar's own default JSON-feed request shape for a remote eventSource - start/end
        // bound whatever range is currently in view, so a teacher's whole multi-year session
        // history is never loaded at once (unlike App\Controller\ProgramController::timetableFeed(),
        // which can ignore range since one Program's own sessions are a naturally small set).
        $start = new \DateTimeImmutable((string) $request->request->get('start', 'now'));
        $end = new \DateTimeImmutable((string) $request->request->get('end', 'now'));
        $sessions = $repository->findAllForTeacherBetween($this->currentUser(), $start, $end);

        return $this->json(array_map(
            static fn ($session): array => $eventFormatter->format($session, editable: false),
            $sessions,
        ));
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
