<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * One fact about what the page did during a supervised passation.
 *
 * Facts, never a counter: "left the page 4 times" cannot be re-read - four 400 ms flickers and one
 * forty-second absence give the same number and have nothing to do with each other. The views
 * aggregate; the table stores what happened and when.
 *
 * Two families sit side by side. `PageHidden`/`WindowBlur` open an absence and their counterparts
 * close it, which is where a duration comes from; the rest are single moments with nothing to pair.
 * `WindowBlur` matters on its own: another window coming in front *without* changing tab is exactly
 * the "I am reading a site next to it" case, and `visibilitychange` never sees it.
 *
 * `TakenOver` is the only one the server writes by itself - see App\Service\QuizAttemptSessionLock.
 */
enum QuizAttemptEventType: string
{
    case PageHidden = 'page_hidden';
    case PageVisible = 'page_visible';
    case WindowBlur = 'window_blur';
    case WindowFocus = 'window_focus';
    case FullscreenExit = 'fullscreen_exit';
    case WindowShrunk = 'window_shrunk';
    case Paste = 'paste';
    case StatementCopied = 'statement_copied';
    case TakenOver = 'taken_over';

    public function labelKey(): string
    {
        return match ($this) {
            self::PageHidden => 'quizEventPageHiddenLabel',
            self::PageVisible => 'quizEventPageVisibleLabel',
            self::WindowBlur => 'quizEventWindowBlurLabel',
            self::WindowFocus => 'quizEventWindowFocusLabel',
            self::FullscreenExit => 'quizEventFullscreenExitLabel',
            self::WindowShrunk => 'quizEventWindowShrunkLabel',
            self::Paste => 'quizEventPasteLabel',
            self::StatementCopied => 'quizEventStatementCopiedLabel',
            self::TakenOver => 'quizEventTakenOverLabel',
        };
    }

    /** Starts an absence whose length is only known once the counterpart arrives. */
    public function opensAbsence(): bool
    {
        return self::PageHidden === $this || self::WindowBlur === $this;
    }

    /** The opener this type closes, null when it closes nothing. */
    public function opener(): ?self
    {
        return match ($this) {
            self::PageVisible => self::PageHidden,
            self::WindowFocus => self::WindowBlur,
            default => null,
        };
    }

    /**
     * Whether a client may report this type at all. Everything but `TakenOver`, which is the
     * server's own statement about which session holds the attempt - a client claiming it would be
     * claiming to have been dispossessed.
     */
    public function isClientReportable(): bool
    {
        return self::TakenOver !== $this;
    }
}
