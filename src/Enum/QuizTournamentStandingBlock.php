<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The four blocks of a tournament's standings (design/validated/quiz-tournament.md §6.2), in the
 * order the page draws them. Only « Classement » carries a rank.
 */
enum QuizTournamentStandingBlock: string
{
    case Ranked = 'ranked';
    case Placement = 'placement';
    case Inactive = 'inactive';
    case NotPlayed = 'not_played';

    public function labelKey(): string
    {
        return match ($this) {
            self::Ranked => 'quizTournamentBlockRanked',
            self::Placement => 'quizTournamentBlockPlacement',
            self::Inactive => 'quizTournamentBlockInactive',
            self::NotPlayed => 'quizTournamentBlockNotPlayed',
        };
    }
}
