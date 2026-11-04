<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

/**
 * One question of a tournament's admitted reserve, as the draw reads it: its id, the source quiz it
 * comes from (the draw shares the duel between sources by their size) and whether it is a vrai/faux
 * (capped per duel).
 */
final readonly class QuizTournamentDrawCandidate
{
    public function __construct(
        public int $questionId,
        public int $sourceId,
        public bool $trueFalse,
    ) {
    }
}
