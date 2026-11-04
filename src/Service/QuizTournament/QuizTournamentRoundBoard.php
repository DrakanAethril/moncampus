<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

use App\Entity\QuizTournamentDuel;
use App\Entity\QuizTournamentRound;
use App\Entity\User;
use App\Repository\QuizTournamentDuelRepository;
use App\Repository\QuizTournamentPlayerRepository;
use App\Service\UserAvatars;

/**
 * The projected screen of a round in class (design/validated/quiz-tournament.md §8 and §10, mockup
 * H-ronde), as one reading for the page and for every refresh of it:
 *
 * - while registrations are open, **the present on the expected**: the participants still expected
 *   whose page beat within QuizTournamentRoundRules::PRESENCE_TIMEOUT_SECONDS, against everybody
 *   the round expects now;
 * - once paired, **each duel** brought up to now through QuizTournamentDuelEngine (the teacher's
 *   screen is a request like any other: it serves what is due and finishes what is over), its
 *   scores and where it stands - never a question - and the exempted player.
 */
class QuizTournamentRoundBoard
{
    public function __construct(
        private readonly QuizTournamentRounds $rounds,
        private readonly QuizTournamentPlayerRepository $players,
        private readonly QuizTournamentDuelRepository $duels,
        private readonly QuizTournamentDuelEngine $engine,
        private readonly UserAvatars $avatars,
    ) {
    }

    /**
     * @return array{
     *     expected: int,
     *     present: list<User>,
     *     missing: int,
     *     duels: list<array{duel: QuizTournamentDuel, a: User, b: User, scoreA: float, scoreB: float, question: int|null, total: int, progress: int, finished: bool, winner: User|null}>,
     *     finished: int,
     *     exempt: User|null,
     *     avatars: array<int, array{photo: string|null, drawn: array<string, mixed>|null}>,
     * }
     */
    public function read(QuizTournamentRound $round, User $viewer, \DateTimeImmutable $now): array
    {
        $expected = $round->isOpen() ? $this->rounds->expectedStudents($round) : [];
        $present = $round->isOpen() ? $this->present($round, $expected, $now) : [];

        $duels = [];
        $people = $present;
        foreach ($round->getPairedAt() ? $this->duels->findForRound($round) : [] as $duel) {
            $duels[] = $this->row($duel, $viewer, $now);
            $people[] = $duel->getPlayerA();
            $people[] = $duel->getPlayerB();
        }
        $exempt = $round->getExempt();
        if (null !== $exempt) {
            $people[] = $exempt;
        }

        return [
            'expected' => \count($expected),
            'present' => $present,
            'missing' => max(0, \count($expected) - \count($present)),
            'duels' => $duels,
            'finished' => \count(array_filter($duels, static fn (array $row): bool => $row['finished'])),
            'exempt' => $exempt,
            'avatars' => $this->avatars->forUsers($people),
        ];
    }

    /**
     * @param list<User> $expected
     *
     * @return list<User> in the class's order
     */
    private function present(QuizTournamentRound $round, array $expected, \DateTimeImmutable $now): array
    {
        $participants = [];
        foreach ($round->getParticipants() as $participant) {
            $participants[(int) $participant->getId()] = true;
        }
        $candidates = array_values(array_filter($expected, static fn (User $user): bool => isset($participants[(int) $user->getId()])));
        $seen = $this->players->lastSeenOf($round->getTournament(), array_map(static fn (User $user): int => (int) $user->getId(), $candidates));

        return array_values(array_filter($candidates, static fn (User $user): bool => QuizTournamentRoundRules::isPresent($seen[(int) $user->getId()] ?? null, $now)));
    }

    /**
     * @return array{duel: QuizTournamentDuel, a: User, b: User, scoreA: float, scoreB: float, question: int|null, total: int, progress: int, finished: bool, winner: User|null}
     */
    private function row(QuizTournamentDuel $duel, User $viewer, \DateTimeImmutable $now): array
    {
        $a = $duel->getPlayerA();
        $b = $duel->getPlayerB();
        $total = $duel->getQuestions()->count();

        // May finish it: the teacher's screen brings the duel up to now like any request.
        $state = $duel->isFinished() ? [] : $this->engine->state($duel, $viewer, $now);

        if ($duel->isFinished()) {
            $scoreA = $duel->getScoreA();
            $scoreB = $duel->getScoreB();

            return [
                'duel' => $duel,
                'a' => $a,
                'b' => $b,
                'scoreA' => $scoreA,
                'scoreB' => $scoreB,
                'question' => null,
                'total' => $total,
                'progress' => 100,
                'finished' => true,
                'winner' => $scoreA > $scoreB ? $a : ($scoreB > $scoreA ? $b : null),
            ];
        }

        $scores = \is_array($state['scores'] ?? null) ? $state['scores'] : [];
        $rank = \is_int($state['rank'] ?? null) ? $state['rank'] : null;

        return [
            'duel' => $duel,
            'a' => $a,
            'b' => $b,
            'scoreA' => self::score($scores[(int) $a->getId()] ?? 0),
            'scoreB' => self::score($scores[(int) $b->getId()] ?? 0),
            'question' => null === $rank ? null : $rank + 1,
            'total' => $total,
            'progress' => null === $rank || 0 === $total ? 0 : (int) round(($rank + 1) / $total * 100),
            'finished' => false,
            'winner' => null,
        ];
    }

    private static function score(mixed $value): float
    {
        return \is_int($value) || \is_float($value) ? (float) $value : 0.0;
    }
}
