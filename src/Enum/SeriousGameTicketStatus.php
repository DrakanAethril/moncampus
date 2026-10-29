<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where a ticket of a scenario stands in the current game (design/validated/serious-games-neko-con.md
 * §3.4) - the columns of the Kanban board.
 */
enum SeriousGameTicketStatus: string implements HasBadge
{
    /** On the board, not opened yet. */
    case Todo = 'todo';
    /** Opened at least once, not closed. */
    case Doing = 'doing';
    /** Resolved. */
    case Done = 'done';
    /** Closed by sending it elsewhere: it was not for the site. */
    case Reassigned = 'reassigned';

    public function isClosed(): bool
    {
        return self::Done === $this || self::Reassigned === $this;
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Todo => 'seriousGameTicketTodo',
            self::Doing => 'seriousGameTicketDoing',
            self::Done => 'seriousGameTicketDone',
            self::Reassigned => 'seriousGameTicketReassigned',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Todo => BadgeTone::Gray,
            self::Doing => BadgeTone::Gold,
            self::Done => BadgeTone::Green,
            self::Reassigned => BadgeTone::Purple,
        };
    }
}
