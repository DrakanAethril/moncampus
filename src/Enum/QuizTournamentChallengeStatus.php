<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The life of a challenge (design/validated/quiz-tournament.md §4): sent and **pending** until the
 * challenged answers; **refused**, or **expired** after 24 hours with no answer; **accepted**, which
 * opens the room for 24 hours; then **played** once the duel has started, or **missed** when the two
 * never met in the room (« rendez-vous manqué », without consequence).
 *
 * A pending row past its expiry, or an accepted one past its room, is read as expired or missed
 * whether or not a task has rewritten it yet - QuizTournamentChallenge::statusAt().
 */
enum QuizTournamentChallengeStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Refused = 'refused';
    case Expired = 'expired';
    case Played = 'played';
    case Missed = 'missed';

    public function labelKey(): string
    {
        return match ($this) {
            self::Pending => 'quizTournamentChallengePending',
            self::Accepted => 'quizTournamentChallengeAccepted',
            self::Refused => 'quizTournamentChallengeRefused',
            self::Expired => 'quizTournamentChallengeExpired',
            self::Played => 'quizTournamentChallengePlayed',
            self::Missed => 'quizTournamentChallengeMissed',
        };
    }
}
