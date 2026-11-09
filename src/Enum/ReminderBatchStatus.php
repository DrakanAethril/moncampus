<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where a reminder batch stands. A batch starts running at the click - there is no scheduled
 * start - and is never deleted: it is the record of what the school told the companies.
 */
enum ReminderBatchStatus: string implements HasBadge
{
    case Running = 'running';
    case Paused = 'paused';
    case Finished = 'finished';
    case Cancelled = 'cancelled';

    /** Running or paused: its pending lines still hold their people (R3). */
    public function isActive(): bool
    {
        return self::Running === $this || self::Paused === $this;
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Running => 'reminderBatchStatusRunningLabel',
            self::Paused => 'reminderBatchStatusPausedLabel',
            self::Finished => 'reminderBatchStatusFinishedLabel',
            self::Cancelled => 'reminderBatchStatusCancelledLabel',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Running => BadgeTone::Blue,
            self::Paused => BadgeTone::Gold,
            self::Finished => BadgeTone::Green,
            self::Cancelled => BadgeTone::Gray,
        };
    }
}
