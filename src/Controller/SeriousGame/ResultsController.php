<?php

declare(strict_types=1);

namespace App\Controller\SeriousGame;

use App\Attribute\RequiresFeature;
use App\Entity\Program;
use App\Entity\SeriousGameAnswer;
use App\Entity\User;
use App\Enum\Feature;
use App\Repository\ProgramRepository;
use App\Repository\SeriousGameRepository;
use App\Security\StructureAccessChecker;
use App\Security\Voter\SeriousGameVoter;
use App\SeriousGame\SeriousGameAggregates;
use App\SeriousGame\SeriousGameClassReading;
use App\SeriousGame\SeriousGamePlayerPage;
use App\SeriousGame\SeriousGameStudentReading;
use App\Service\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The reading of the results (design/validated/serious-games.md §3.2, §8,
 * design/conception_serious_games/suivi.html):
 *
 * - a teacher reads the classes they teach, in every game - even one not lit for them - and every
 *   student of those classes, their whole history included;
 * - an administrator reads every class and every student;
 * - staff and staff-lead read figures without names;
 * - a tutor or an external account reads nothing.
 *
 * Nothing here plays and nothing is sent to a student: no work, no reminder, no mark.
 */
#[RequiresFeature(Feature::SeriousGames)]
class ResultsController extends AbstractController
{
    use SeriousGameControllerTrait;

    #[Route(path: '/serious-games/results', name: 'app_serious_games_results', methods: ['GET'])]
    public function index(SeriousGameRepository $games, ProgramRepository $programs, StructureAccessChecker $structureAccess, SeriousGameAggregates $aggregates): Response
    {
        $user = $this->currentPlayer();
        $admin = $this->isGranted('ROLE_ADMIN');
        $teacher = $this->isGranted('ROLE_TEACHER');
        $readsAggregates = $this->isGranted(SeriousGameVoter::READ_AGGREGATE);
        if (!$teacher && !$admin && !$readsAggregates) {
            throw $this->createNotFoundException();
        }

        $all = array_values(array_filter($programs->findAllActiveWithStudents(), $structureAccess->matchesTestMode(...)));
        $classes = $admin ? $all : ($teacher ? $programs->findAllForTeacher($user) : []);
        $allGames = $games->findAllOrdered();

        return $this->render('serious_game/results/index.html.twig', [
            'games' => $allGames,
            'classes' => $classes,
            'aggregates' => $readsAggregates ? array_map(static fn ($game): array => ['game' => $game, 'rows' => $aggregates->perClass($game, $all)], $allGames) : [],
        ]);
    }

    #[Route(path: '/serious-games/results/{program}/{slug}', name: 'app_serious_games_results_class', requirements: ['program' => '\d+'], methods: ['GET'])]
    public function class(Request $request, Program $program, string $slug, SeriousGameRepository $games, SeriousGameClassReading $reading): Response
    {
        $game = $games->findOneBySlug($slug);
        if (null === $game || !$this->isGranted(SeriousGameVoter::READ_CLASS, $program)) {
            throw $this->createNotFoundException();
        }

        $filter = QueryValue::string($request, 'students');

        return $this->render('serious_game/results/class.html.twig', [
            'program' => $program,
            'game' => $game,
            'reading' => $reading->forClass($program, $game),
            'chapter' => QueryValue::string($request, 'chapter'),
            'filter' => \in_array($filter, ['dim', 'inactive'], true) ? $filter : 'all',
            'order' => 'activity' === QueryValue::string($request, 'order') ? 'activity' : 'name',
        ]);
    }

    #[Route(path: '/serious-games/results/student/{user}', name: 'app_serious_games_results_student', requirements: ['user' => '\d+'], methods: ['GET'])]
    public function student(User $user, SeriousGamePlayerPage $page, SeriousGameStudentReading $reading, SeriousGameRepository $games): Response
    {
        if (!$this->isGranted(SeriousGameVoter::READ_STUDENT, $user) || $user === $this->getUser()) {
            throw $this->createNotFoundException();
        }

        return $this->render('serious_game/results/student.html.twig', $page->build($user, false) + [
            'details' => array_map(static fn ($game): array => $reading->forStudent($user, $game), $games->findAllOrdered()),
        ]);
    }

    #[Route(path: '/serious-games/results/student/{user}/answers/{answer}', name: 'app_serious_games_results_replay', requirements: ['user' => '\d+', 'answer' => '\d+'], methods: ['GET'])]
    public function replay(User $user, SeriousGameAnswer $answer, SeriousGameStudentReading $reading): Response
    {
        if (!$this->isGranted(SeriousGameVoter::READ_STUDENT, $user) || $answer->getSession()->getPlayer() !== $user) {
            throw $this->createNotFoundException();
        }

        return $this->render('serious_game/results/replay.html.twig', ['player' => $user] + $reading->replay($answer));
    }
}
