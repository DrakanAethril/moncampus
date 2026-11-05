<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

/**
 * One question of a duel on the duel's clock (QuizTournamentDuelClock): when it is (or was) served,
 * until when each player may answer it - their own deadline, extra time included - and when it
 * closed: once each player answered or ran out of time. $deadlineAt is the last of the deadlines,
 * the moment the question stops taking answers from anybody. $stamped says whether $servedAt is the
 * one stored on the question or a computed one.
 */
final readonly class QuizTournamentDuelMoment
{
    public \DateTimeImmutable $deadlineAt;

    /** @param array<int, \DateTimeImmutable> $deadlines player => their deadline */
    public function __construct(
        public int $rank,
        public \DateTimeImmutable $servedAt,
        public array $deadlines,
        public \DateTimeImmutable $closesAt,
        public bool $stamped,
    ) {
        $this->deadlineAt = [] === $deadlines ? $servedAt : max($deadlines);
    }

    /** This player's deadline, or null for somebody who is not in the duel. */
    public function deadlineFor(int $player): ?\DateTimeImmutable
    {
        return $this->deadlines[$player] ?? null;
    }
}
