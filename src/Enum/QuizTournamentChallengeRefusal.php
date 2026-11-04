<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Why a challenge cannot be sent (design/validated/quiz-tournament.md §4.1) - one case per rule of
 * App\Service\QuizTournament\QuizTournamentChallengeRules, so the greyed « Défier » button can say
 * which one. The sentences take `%name%`, the challenged player's name, where they speak of them.
 */
enum QuizTournamentChallengeRefusal: string
{
    case NotInProgress = 'not_in_progress';
    case ChallengerNotEligible = 'challenger_not_eligible';
    case SamePlayer = 'same_player';
    case ChallengedNotEligible = 'challenged_not_eligible';
    case OutgoingPending = 'outgoing_pending';
    case TooManyIncoming = 'too_many_incoming';
    case RecentlyRefused = 'recently_refused';
    case DailyPairCap = 'daily_pair_cap';

    public function labelKey(): string
    {
        return match ($this) {
            self::NotInProgress => 'quizTournamentRefusalNotInProgress',
            self::ChallengerNotEligible => 'quizTournamentRefusalChallengerNotEligible',
            self::SamePlayer => 'quizTournamentRefusalSamePlayer',
            self::ChallengedNotEligible => 'quizTournamentRefusalChallengedNotEligible',
            self::OutgoingPending => 'quizTournamentRefusalOutgoingPending',
            self::TooManyIncoming => 'quizTournamentRefusalTooManyIncoming',
            self::RecentlyRefused => 'quizTournamentRefusalRecentlyRefused',
            self::DailyPairCap => 'quizTournamentRefusalDailyPairCap',
        };
    }
}
