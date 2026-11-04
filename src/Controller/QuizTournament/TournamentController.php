<?php

declare(strict_types=1);

namespace App\Controller\QuizTournament;

use App\Attribute\RequiresFeature;
use App\Entity\QuizTournament;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\QuizTournamentChallengeRefusal;
use App\Repository\QuizTournamentRepository;
use App\Repository\QuizTournamentRoundRepository;
use App\Security\Voter\QuizTournamentVoter;
use App\Service\QuizTournament\QuizTournamentAccess;
use App\Service\QuizTournament\QuizTournamentChallengeFacts;
use App\Service\QuizTournament\QuizTournamentChallengeRules;
use App\Service\QuizTournament\QuizTournamentLive;
use App\Service\QuizTournament\QuizTournamentRounds;
use App\Service\QuizTournament\QuizTournamentStandingsAssembler;
use App\Service\QuizTournament\QuizTournamentStandingsView;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The quiz tournaments as their readers see them (design/validated/quiz-tournament.md §6 and §10):
 * the list of the tournaments one may read, and a tournament's page - its standings, in the
 * tournament's own dress (H « Pop », templates/quiz_tournament/_layout.html.twig).
 *
 * Who reads what is QuizTournamentVoter::VIEW alone - an eligible student, a teacher of a targeted
 * formation, the staff once it is opened, the administrators always. A refusal is a 404.
 */
#[RequiresFeature(Feature::QuizTournaments)]
class TournamentController extends AbstractController
{
    /** How far back the tournament page offers a teacher the rounds they opened, to project again. */
    private const int TEACHER_ROUNDS_HOURS = 12;

    public function __construct(
        private readonly QuizTournamentRepository $tournaments,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(path: '/tournaments', name: 'app_quiz_tournaments', methods: ['GET'])]
    public function index(): Response
    {
        // An administrator reads every tournament, closed ones included; everybody else only ever
        // the opened ones, then what the voter lets them read.
        $candidates = $this->isGranted(QuizTournamentVoter::CREATE) ? $this->tournaments->findAllOrdered() : $this->tournaments->findOpened();
        $now = $this->clock->now();

        $rows = [];
        foreach ($candidates as $tournament) {
            if ($this->isGranted(QuizTournamentVoter::VIEW, $tournament)) {
                $rows[] = ['tournament' => $tournament, 'status' => $tournament->statusAt($now)];
            }
        }

        return $this->render('quiz_tournament/index.html.twig', ['rows' => $rows]);
    }

    #[Route(path: '/tournaments/{id}', name: 'app_quiz_tournament_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(
        int $id,
        Request $request,
        QuizTournamentStandingsAssembler $assembler,
        QuizTournamentAccess $access,
        QuizTournamentLive $live,
        QuizTournamentRounds $rounds,
        QuizTournamentRoundRepository $roundRepository,
    ): Response {
        $tournament = $this->viewable($id);
        $now = $this->clock->now();
        $standings = $assembler->assemble($tournament, $now);

        /** @var User $reader */
        $reader = $this->getUser();
        $canPlay = $this->isGranted(QuizTournamentVoter::PLAY, $tournament);

        return $this->render('quiz_tournament/show.html.twig', [
            'tournament' => $tournament,
            'status' => $tournament->statusAt($now),
            'standings' => $standings,
            'readerLine' => $standings->lineFor($reader->getId()),
            'canPlay' => $canPlay,
            'defyRefusals' => $canPlay ? $this->defyRefusals($tournament, $reader, $standings, $access, $now) : [],
            'tournamentLive' => $live->screen($request, $tournament, $reader, $now),
            'activeTab' => 'standings',
            // §8: the round card of a student, the rounds of a teacher.
            'roundEntry' => $rounds->entryFor($tournament, $reader, $now),
            'canOpenRound' => $this->isGranted(QuizTournamentVoter::OPEN_ROUND, $tournament),
            'teacherRounds' => $this->isGranted('ROLE_TEACHER')
                ? $roundRepository->findRecentForTeacher($tournament, $reader, $now->modify(\sprintf('-%d hours', self::TEACHER_ROUNDS_HOURS)))
                : [],
        ]);
    }

    /**
     * Why each « Défier » of the standings is greyed out, if it is: QuizTournamentChallengeRules on
     * the facts of every eligible line, loaded at once.
     *
     * @return array<int, QuizTournamentChallengeRefusal|null> user id => the first refusal
     */
    private function defyRefusals(QuizTournament $tournament, User $reader, QuizTournamentStandingsView $standings, QuizTournamentAccess $access, \DateTimeImmutable $now): array
    {
        $eligible = [];
        foreach ($standings->standings->all() as $row) {
            if ($row->entry->eligible && $row->entry->userId !== $reader->getId()) {
                $eligible[$row->entry->userId] = true;
            }
        }

        return array_map(
            static fn (QuizTournamentChallengeFacts $facts): ?QuizTournamentChallengeRefusal => QuizTournamentChallengeRules::firstRefusal($facts, $now),
            $access->challengeFactsByOpponent($tournament, $reader, $eligible, $now),
        );
    }

    private function viewable(int $id): QuizTournament
    {
        $tournament = $this->tournaments->find($id);
        if (null === $tournament || !$this->isGranted(QuizTournamentVoter::VIEW, $tournament)) {
            throw $this->createNotFoundException();
        }

        return $tournament;
    }
}
