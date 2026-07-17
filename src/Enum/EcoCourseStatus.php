<?php

namespace App\Enum;

// Manual cycle only (screen 1g): a teacher explicitly starts/closes a course, it never flips on
// its own from dates/timers.
enum EcoCourseStatus: string
{
    case Prepared = 'prepared';
    case InProgress = 'in_progress';
    case Closed = 'closed';

    public function labelKey(): string
    {
        return match ($this) {
            self::Prepared => 'ecoCourseStatusPreparedLabel',
            self::InProgress => 'ecoCourseStatusInProgressLabel',
            self::Closed => 'ecoCourseStatusClosedLabel',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Prepared => 'bg-blue-lt',
            self::InProgress => 'bg-green-lt',
            self::Closed => 'bg-secondary-lt',
        };
    }
}
