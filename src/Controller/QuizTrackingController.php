<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\Program;
use App\Entity\QuizInstance;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\QuizInstanceState;
use App\Repository\ProgramRepository;
use App\Repository\QuizAttemptRepository;
use App\Repository\QuizInstanceRepository;
use App\Service\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Quiz par classes" - the launched quizzes of every class the viewer teaches, on one screen, from
 * Outils > Suivre les étudiants.
 *
 * Deliberately not a class picker like its neighbours (App\Controller\ToolsController): the
 * question a teacher asks here is "where do my quizzes stand", which spans classes, so the class
 * is a filter on the list rather than a gate in front of it.
 *
 * It shows the classes one TEACHES, with no staff fallback - unlike every other tool of that menu.
 * A launched quiz is a teacher's own work and its results are their students' answers; an
 * administrator has no business reading them from here, which is also why the per-class Quiz entry
 * left the class submenu when this screen arrived. Whoever teaches nothing therefore sees an empty
 * screen, and that is the correct answer rather than an accident.
 */
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
#[RequiresFeature(Feature::QuizLibrary)]
class QuizTrackingController extends AbstractController
{
    #[Route(path: '/tools/quiz', name: 'app_tools_quiz', methods: ['GET'])]
    public function index(Request $request, ProgramRepository $programRepository, QuizInstanceRepository $instanceRepository, QuizAttemptRepository $attemptRepository): Response
    {
        /** @var User $viewer */
        $viewer = $this->getUser();

        $programs = $programRepository->findAllForTeacher($viewer);
        $instances = $instanceRepository->findForPrograms($programs);

        $now = new \DateTimeImmutable();
        $states = [];
        foreach ($instances as $instance) {
            $states[(int) $instance->getId()] = QuizInstanceState::of($instance->getOpensAt(), $instance->getClosesAt(), $now);
        }

        // The class filter is read against the taught list, never trusted: an id for a class the
        // viewer doesn't teach simply falls back to "toutes les classes" rather than 403-ing, the
        // same way an unknown state falls back to the default one.
        $programFilter = $this->resolveProgramFilter($request, $programs);
        $state = QuizInstanceState::tryFrom((string) $request->query->get('state', '')) ?? QuizInstanceState::Ongoing;

        $inScope = array_values(array_filter(
            $instances,
            static fn (QuizInstance $instance): bool => null === $programFilter || $instance->getProgram() === $programFilter,
        ));

        // Counted after the class filter and before the state one, so the tabs say how many
        // quizzes each state holds *for the class being looked at*.
        $counts = array_fill_keys(array_column(QuizInstanceState::cases(), 'value'), 0);
        foreach ($inScope as $instance) {
            ++$counts[$states[(int) $instance->getId()]->value];
        }

        $rows = [];
        foreach ($inScope as $instance) {
            if ($states[(int) $instance->getId()] !== $state) {
                continue;
            }

            $rows[] = [
                'instance' => $instance,
                'state' => $states[(int) $instance->getId()],
                'concludedCount' => \count($attemptRepository->findConcludedForInstance($instance)),
                'studentCount' => $instance->getProgram()?->getStudents()->count() ?? 0,
            ];
        }

        return $this->render('tools/quiz_tracking.html.twig', [
            'programs' => $programs,
            'programFilter' => $programFilter,
            'state' => $state,
            'counts' => $counts,
            'rows' => $rows,
        ]);
    }

    /** @param list<Program> $programs */
    private function resolveProgramFilter(Request $request, array $programs): ?Program
    {
        // The "Toutes les classes" option is blank, and the toolbar auto-submits: `?program=` is a
        // routine request, not a malformed one. getInt() would answer it with a 400.
        $id = QueryValue::int($request, 'program');

        foreach ($programs as $program) {
            if ($program->getId() === $id) {
                return $program;
            }
        }

        return null;
    }
}
