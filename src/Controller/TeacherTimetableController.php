<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\Feature;
use App\Repository\LessonSessionRepository;
use App\Service\LessonSessionEventFormatter;
use App\Service\NameColorGenerator;
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
#[RequiresFeature(Feature::Timetable)]
class TeacherTimetableController extends AbstractController
{
    use CalendarFeedRangeTrait;

    #[Route(path: '/timetable', name: 'app_teacher_timetable')]
    public function index(LessonSessionRepository $repository, NameColorGenerator $colorGenerator): Response
    {
        // Same color a session gets on the calendar itself (LessonSessionEventFormatter's
        // colorByProgram mode) - computed once here from the exact same generator so a legend
        // swatch and its Program's events are guaranteed to match, without persisting a color
        // choice anywhere.
        $formations = array_map(
            static fn (Program $program): array => [
                'id' => $program->getId(),
                'name' => $program->getDisplayShortName(),
                'color' => $colorGenerator->generate($program->getShortName()),
            ],
            $repository->findDistinctProgramsForTeacher($this->currentUser()),
        );

        return $this->render('teacher/timetable.html.twig', ['formations' => $formations]);
    }

    #[Route(path: '/timetable/feed', name: 'app_teacher_timetable_feed')]
    public function feed(Request $request, LessonSessionRepository $repository, LessonSessionEventFormatter $eventFormatter): JsonResponse
    {
        [$start, $end] = $this->calendarFeedRange($request);
        $sessions = $repository->findAllForTeacherBetween($this->currentUser(), $start, $end);

        return $this->json(array_map(
            static fn ($session): array => $eventFormatter->format($session, editable: false, colorByProgram: true),
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
