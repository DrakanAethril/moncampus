<?php

declare(strict_types=1);

namespace App\Enum;

// State machine driving a QuizLiveSession's projector/player screens - see
// design/design_campus_manager/reference/Générateur de quiz.dc.html, Turns 1q/1u/1h/1i/1j. The
// teacher manually advances through every step (QuizLiveSessionService::advance()) - nothing here
// auto-transitions on a timer, even though Question/Countdown both have a countdown displayed.
enum LiveSessionStatus: string
{
    case Lobby = 'lobby';
    case Countdown = 'countdown';
    case Question = 'question';
    case Reveal = 'reveal';
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

    public function labelKey(): string
    {
        return match ($this) {
            self::Lobby => 'liveSessionStatusLobbyLabel',
            self::Countdown => 'liveSessionStatusCountdownLabel',
            self::Question => 'liveSessionStatusQuestionLabel',
            self::Reveal => 'liveSessionStatusRevealLabel',
            self::Finished => 'liveSessionStatusFinishedLabel',
            self::Cancelled => 'liveSessionStatusCancelledLabel',
        };
    }
}
