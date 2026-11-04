<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Why a gesture of a round in class is refused (design/validated/quiz-tournament.md §8) - opening
 * it, joining it, closing it - one case per rule of App\Service\QuizTournament\QuizTournamentRoundRules,
 * so the screen can say which.
 */
enum QuizTournamentRoundRefusal: string
{
    case NotAllowed = 'not_allowed';
    case AlreadyOpen = 'already_open';
    case OptionNotInClass = 'option_not_in_class';
    case GroupNotAvailable = 'group_not_available';
    case TwoRestrictions = 'two_restrictions';
    case RoundClosed = 'round_closed';
    case CannotPlay = 'cannot_play';
    case NotExpected = 'not_expected';
    case TooFew = 'too_few';

    public function labelKey(): string
    {
        return match ($this) {
            self::NotAllowed => 'quizTournamentRoundRefusalNotAllowed',
            self::AlreadyOpen => 'quizTournamentRoundRefusalAlreadyOpen',
            self::OptionNotInClass => 'quizTournamentRoundRefusalOptionNotInClass',
            self::GroupNotAvailable => 'quizTournamentRoundRefusalGroupNotAvailable',
            self::TwoRestrictions => 'quizTournamentRoundRefusalTwoRestrictions',
            self::RoundClosed => 'quizTournamentRoundRefusalRoundClosed',
            self::CannotPlay => 'quizTournamentRoundRefusalCannotPlay',
            self::NotExpected => 'quizTournamentRoundRefusalNotExpected',
            self::TooFew => 'quizTournamentRoundRefusalTooFew',
        };
    }
}
