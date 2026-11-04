<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

/**
 * One question of a duel on the duel's clock (QuizTournamentDuelClock): when it is (or was) served,
 * when it stops taking answers, and when it closed - at its deadline, or as soon as both players
 * answered. $stamped says whether $servedAt is the one stored on the question or a computed one.
 */
final readonly class QuizTournamentDuelMoment
{
    public function __construct(
        public int $rank,
        public \DateTimeImmutable $servedAt,
        public \DateTimeImmutable $deadlineAt,
        public \DateTimeImmutable $closesAt,
        public bool $stamped,
    ) {
    }
}
