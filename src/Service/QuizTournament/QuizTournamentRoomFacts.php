<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

/**
 * What QuizTournamentRoomRules decides on, loaded beforehand for one person in one room: « player »
 * is the person who clicks « Prêt » (or whose request may start the duel), « opponent » the other.
 *
 * $started: the challenge already has its duel. $playerBusy / $opponentBusy: an unfinished duel of
 * theirs exists elsewhere (QuizTournamentDuelRepository::findUnfinishedFor()) - « un seul duel à la
 * fois » (§4.3).
 */
final readonly class QuizTournamentRoomFacts
{
    public function __construct(
        public bool $roomOpen,
        public bool $inProgress,
        public bool $started,
        public bool $playerReady,
        public bool $opponentReady,
        public bool $playerEligible,
        public bool $opponentEligible,
        public bool $playerBusy,
        public bool $opponentBusy,
    ) {
    }
}
