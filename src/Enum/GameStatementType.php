<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a relevé is for. Two kinds, and the type is what decides which fields it carries.
 *
 * A relevé is **named and created by hand**, and there may be as many or as few of them as a team
 * wants - three councils in a year, or one; a weekly attendance pass, or a monthly one, or none at
 * all. That is the whole point of the type living here rather than of the calendar deciding: the
 * school's evaluation periods still carry the *index* (§4, decision 2 - the game invents no
 * calendar), but they no longer dictate how many documents a class fills in.
 */
enum GameStatementType: string
{
    /** Three states, no absence recorded anywhere - see App\Enum\AttendanceState. */
    case Attendance = 'attendance';

    /** One mention per student, entered in one pass while the council deliberates. */
    case Council = 'council';

    public function labelKey(): string
    {
        return match ($this) {
            self::Attendance => 'gameStatementTypeAttendanceLabel',
            self::Council => 'gameStatementTypeCouncilLabel',
        };
    }

    public function helpKey(): string
    {
        return match ($this) {
            self::Attendance => 'gameStatementTypeAttendanceHelpText',
            self::Council => 'gameStatementTypeCouncilHelpText',
        };
    }

    /** Only an attendance relevé covers a stretch of time; a council happens on a day. */
    public function coversATimeSpan(): bool
    {
        return self::Attendance === $this;
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Attendance => 'blue',
            self::Council => 'gold',
        };
    }
}
