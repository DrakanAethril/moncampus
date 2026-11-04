<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Why « Prêt » is refused in a duel's room, or why the duel does not start although both are ready
 * (design/validated/quiz-tournament.md §4.3) - one case per rule of
 * App\Service\QuizTournament\QuizTournamentRoomRules, so the room can say which. « Player » is the
 * person asking, « opponent » the other one; the sentences take `%name%`, the opponent's first name.
 */
enum QuizTournamentRoomRefusal: string
{
    case RoomClosed = 'room_closed';
    case NotInProgress = 'not_in_progress';
    case PlayerNotEligible = 'player_not_eligible';
    case OpponentNotEligible = 'opponent_not_eligible';
    case PlayerBusy = 'player_busy';
    case OpponentBusy = 'opponent_busy';

    public function labelKey(): string
    {
        return match ($this) {
            self::RoomClosed => 'quizTournamentRoomRefusalRoomClosed',
            self::NotInProgress => 'quizTournamentRoomRefusalNotInProgress',
            self::PlayerNotEligible => 'quizTournamentRoomRefusalPlayerNotEligible',
            self::OpponentNotEligible => 'quizTournamentRoomRefusalOpponentNotEligible',
            self::PlayerBusy => 'quizTournamentRoomRefusalPlayerBusy',
            self::OpponentBusy => 'quizTournamentRoomRefusalOpponentBusy',
        };
    }
}
