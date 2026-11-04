<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

use App\Enum\QuizTournamentStandingBlock;

/**
 * A line of the standings: its block, its rank (« Classement » only, equal ratings sharing one), and
 * what an inactive player still lacks - « encore 1 duel », « un duel contre un joueur proche ».
 */
final readonly class QuizTournamentStandingRow
{
    public function __construct(
        public QuizTournamentStandingEntry $entry,
        public QuizTournamentStandingBlock $block,
        public ?int $rank,
        public int $missingRecentDuels,
        public bool $missingLadder,
    ) {
    }
}
