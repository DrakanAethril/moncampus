<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

/** What QuizTournamentRoundPairing::pair() decides: the duels, and who sits this round out. */
final readonly class QuizTournamentRoundPairs
{
    /** @param list<array{0: int, 1: int}> $pairs user ids, the higher rated first, pairs by rating */
    public function __construct(
        public array $pairs,
        public ?int $exemptId,
    ) {
    }
}
