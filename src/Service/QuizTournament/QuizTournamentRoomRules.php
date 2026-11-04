<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

use App\Enum\QuizTournamentRoomRefusal;

/**
 * The room of an accepted challenge (design/validated/quiz-tournament.md §4.3), as pure functions on
 * facts loaded beforehand (QuizTournamentRoomFacts):
 *
 * - « Prêt » needs an open room, a running tournament, a player still eligible, and **neither
 *   player in another duel** - several rooms open at once, yes; two duels at once, no;
 * - the duel starts when both are ready, the same conditions read again for both - eligibility is
 *   read at every start (§2), never frozen - and only once: a room whose challenge already has its
 *   duel starts nothing more.
 *
 * The heartbeat that keeps « Prêt » fresh is QuizTournamentChallengeRules::isStillReady().
 */
final class QuizTournamentRoomRules
{
    public static function readyRefusal(QuizTournamentRoomFacts $facts): ?QuizTournamentRoomRefusal
    {
        return match (true) {
            !$facts->roomOpen => QuizTournamentRoomRefusal::RoomClosed,
            !$facts->inProgress => QuizTournamentRoomRefusal::NotInProgress,
            !$facts->playerEligible => QuizTournamentRoomRefusal::PlayerNotEligible,
            $facts->playerBusy => QuizTournamentRoomRefusal::PlayerBusy,
            $facts->opponentBusy => QuizTournamentRoomRefusal::OpponentBusy,
            default => null,
        };
    }

    /** Why the duel would not start now, both being ready - null when nothing stands in the way. */
    public static function startRefusal(QuizTournamentRoomFacts $facts): ?QuizTournamentRoomRefusal
    {
        return self::readyRefusal($facts)
            ?? (!$facts->opponentEligible ? QuizTournamentRoomRefusal::OpponentNotEligible : null);
    }

    public static function shouldStart(QuizTournamentRoomFacts $facts): bool
    {
        return !$facts->started
            && $facts->playerReady
            && $facts->opponentReady
            && null === self::startRefusal($facts);
    }
}
