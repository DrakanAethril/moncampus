<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

/**
 * One person as QuizTournamentStanding reads them: a player with their counters, or an eligible
 * student who has not played yet (duels 0, rating QuizTournamentElo::START).
 *
 * $recentDuels and $ladderMet are counted over the 14 days before the standings' moment (now, or the
 * tournament's end once it is over): $ladderMet says that one of those duels was against one of the
 * five ranked players nearest in rating *at the time of the duel*, or that a challenge sent to one
 * of them was refused or expired - a flag stored when the duel or the challenge happened
 * (QuizTournamentStanding::isLadderOpponent()), since « at the time » cannot be recomputed later.
 */
final readonly class QuizTournamentStandingEntry
{
    public function __construct(
        public int $userId,
        public string $sortName,
        public int $rating,
        public int $duels,
        public int $recentDuels,
        public bool $ladderMet,
        public bool $eligible,
    ) {
    }
}
