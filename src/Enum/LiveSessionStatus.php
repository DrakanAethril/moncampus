<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * State machine driving a QuizLiveSession's projector and player screens, Kahoot's own run of a
 * question (the user's decision of 2026-11-05, « on se rapproche de Kahoot »):
 *
 * Lobby -> Countdown -> [Reading -> Question -> Results -> Scoreboard] x N -> Finished
 *
 * - Reading: the statement alone on the board, the phones saying « Regardez le tableau ». The host
 *   shows the answers.
 * - Question: the propositions and the clock. It closes by itself when the clock runs out or when
 *   everybody has answered, or earlier on the host's « Passer ».
 * - Results: what the room answered, the right answer, each phone its own verdict and points.
 * - Scoreboard: the top of the ranking, on its own screen. Skipped after a vote (nobody's score
 *   moved) and after the last question (the podium follows).
 *
 * Every other step waits for the host (App\Service\QuizLiveSessionService::advance()); the
 * Countdown ends on the host's own screen.
 */
enum LiveSessionStatus: string implements HasBadge
{
    case Lobby = 'lobby';
    case Countdown = 'countdown';
    case Reading = 'reading';
    case Question = 'question';
    case Results = 'results';
    case Scoreboard = 'scoreboard';
    case Finished = 'finished';
    case Cancelled = 'cancelled';

    /**
     * Terminal states, the pair that the delete guard, the archive query and the Concours live
     * screen all ask about - one answer rather than three copies of the same `in_array`.
     */
    public function isOver(): bool
    {
        return self::Finished === $this || self::Cancelled === $this;
    }

    /**
     * The states of a session under way - the room a student can still join.
     *
     * @return list<self>
     */
    public static function running(): array
    {
        return [self::Lobby, self::Countdown, self::Reading, self::Question, self::Results, self::Scoreboard];
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Lobby => 'liveSessionStatusLobbyLabel',
            self::Countdown => 'liveSessionStatusCountdownLabel',
            self::Reading => 'liveSessionStatusReadingLabel',
            self::Question => 'liveSessionStatusQuestionLabel',
            self::Results => 'liveSessionStatusRevealLabel',
            self::Scoreboard => 'liveSessionStatusScoreboardLabel',
            self::Finished => 'liveSessionStatusFinishedLabel',
            self::Cancelled => 'liveSessionStatusCancelledLabel',
        };
    }

    /** Under way in green, over in grey, cancelled in red - Outils › Concours live's rows. */
    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Finished => BadgeTone::Gray,
            self::Cancelled => BadgeTone::Red,
            default => BadgeTone::Green,
        };
    }
}
