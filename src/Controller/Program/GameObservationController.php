<?php

declare(strict_types=1);

namespace App\Controller\Program;

use App\Attribute\RequiresFeature;
use App\Entity\Program;
use App\Enum\Feature;
use App\Enum\GameFamily;
use App\Repository\GameEntryRepository;
use App\Repository\ProgramRepository;
use App\Service\Game\GameAccess;
use App\Service\Game\GameCollector;
use App\Service\Game\GameMonth;
use App\Service\Game\GameObservationBoard;
use App\Service\Game\GameRuleResolver;
use App\Service\Game\GameYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Observation » - the administration's reading of a class's game, and the screen a pilot is run
 * from.
 *
 * It exists for one question the rest of the game cannot answer: **are these points and these
 * thresholds the right ones**. The barème screen says what a rule is worth; only this one says what
 * it produced, how much of the class's total it makes, and how many months of the observed pace each
 * level would take. A rule nobody ever triggers leaves no trace at all in the ledger, so it is listed
 * here with its zero rather than left out.
 *
 * **Administrators only**, like the barème and the catalogue it goes with. Two reasons rather than
 * one: the reading is a settling gesture while the game is being tuned, and it is the only screen of
 * the game that names students beside their index - the class ranking is anonymous by design, and
 * this one deliberately is not, because a threshold cannot be judged against pseudonyms.
 *
 * It is also what makes a **silent pilot** possible: with `game` unticked for every managed role, a
 * formation plays without a single student seeing anything, and this is where it is read.
 */
#[IsGranted('ROLE_USER')]
#[RequiresFeature(Feature::Game)]
class GameObservationController extends AbstractController
{
    /** How many journal lines a page shows - the whole class's, so a month is a long list. */
    private const int JOURNAL_LIMIT = 300;

    #[Route(path: '/programs/{id}/game/observation', name: 'app_program_game_observation', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function index(
        int $id,
        Request $request,
        ProgramRepository $programs,
        GameAccess $access,
        GameObservationBoard $board,
        GameEntryRepository $entries,
        GameRuleResolver $rules,
    ): Response {
        $program = $this->openProgram($id, $programs, $access);

        $month = GameMonth::fromKey((string) $request->query->get('month', '')) ?? GameMonth::of(new \DateTimeImmutable());
        $year = GameYear::forProgram($program);
        $scope = 'year' === $request->query->get('scope') ? 'year' : 'month';

        [$from, $to] = 'year' === $scope
            ? [$year->from, $year->to]
            : [$month->firstDay(), $month->lastMoment()];

        // The journal's own filter, read as a plain string: an unknown code simply matches nothing.
        $rule = trim((string) $request->query->get('rule', ''));

        return $this->render('game/observation.html.twig', [
            'program' => $program,
            'observation' => $board->for($program, $from, $to),
            'month' => $month,
            'year' => $year,
            'scope' => $scope,
            'rule' => $rule,
            'rules' => $rules->all($program),
            'families' => GameFamily::cases(),
            'journal' => $entries->journalForProgram($program, $from, $to, '' === $rule ? null : $rule, self::JOURNAL_LIMIT),
            'journalLimit' => self::JOURNAL_LIMIT,
        ]);
    }

    /**
     * Bring the whole class's window up to date, now, rather than at the next closure.
     *
     * Collection normally happens either when a student opens their own screen or inside a monthly
     * closure - and a pilot run with the game hidden from students has neither. Cheap to repeat:
     * everything already written is refused by the ledger, so this is idempotent by construction.
     *
     * One flush for the whole class, like the closure does. A very large class against PHP's 30 s
     * `max_execution_time` is the limit here; the cron pays the same cost nightly without a browser
     * waiting on it.
     */
    #[Route(path: '/programs/{id}/game/observation/collect', name: 'app_program_game_observation_collect', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function collect(
        int $id,
        Request $request,
        ProgramRepository $programs,
        GameAccess $access,
        GameCollector $collector,
        EntityManagerInterface $entityManager,
    ): Response {
        $program = $this->openProgram($id, $programs, $access);

        if (!$this->isCsrfTokenValid('game_observation_collect', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $month = GameMonth::fromKey((string) $request->request->get('month', '')) ?? GameMonth::of(new \DateTimeImmutable());
        $now = new \DateTimeImmutable();

        foreach ($program->getStudents() as $student) {
            $collector->collectWithoutFlush($student, $program, $month->firstDay(), $month->lastMoment(), $now);
        }

        $entityManager->flush();

        $this->addFlash('success', 'gameObservationCollectedFlashMessage');

        return $this->redirectToRoute('app_program_game_observation', ['id' => $program->getId(), 'month' => $month->key()]);
    }

    private function openProgram(int $id, ProgramRepository $programs, GameAccess $access): Program
    {
        $program = $programs->find($id) ?? throw $this->createNotFoundException();

        if (!$access->isOpen($program)) {
            throw $this->createNotFoundException();
        }

        // Administrators alone, and stricter than the screens a teacher holds: this one names every
        // student beside their index, and it is read to settle a barème rather than to run a class.
        if (!$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        return $program;
    }
}
