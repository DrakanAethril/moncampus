<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

/** A player present when the round is closed: their rating, and how often a round exempted them. */
final readonly class QuizTournamentRoundEntrant
{
    public function __construct(
        public int $userId,
        public int $rating,
        public int $exemptions,
    ) {
    }
}
