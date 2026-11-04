<?php

declare(strict_types=1);

namespace App\Service\Play;

use App\Entity\QuizTournament;
use App\Enum\QuizTournamentStandingBlock;
use App\Enum\QuizTournamentStatus;

/**
 * One tournament on the student's « Jouer » page (design/validated/quiz-tournament.md §15): where it
 * stands, where the student stands in it - their rank, or their block when they are not ranked, and
 * their rating - and the challenges waiting for them (received + open rooms, the « Défis » tab's own
 * counter). That count is the page's content, not an alert: nothing else on the platform shows it.
 */
final readonly class PlayTournamentCard
{
    public function __construct(
        public QuizTournament $tournament,
        public QuizTournamentStatus $status,
        public QuizTournamentStandingBlock $block,
        public ?int $rank,
        public int $rating,
        public int $pendingChallenges,
    ) {
    }
}
