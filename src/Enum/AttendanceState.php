<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The three answers of the relevé, and there will never be a fourth
 * (design/validated/gamification.md §4, decision 3).
 *
 * The whole point of this enum is what it does **not** carry. There is no reason, no date, no
 * counter, no « justifiée » / « injustifiée »: « pas net » says that something happened during that
 * week, and nothing else. MonCampus holds no attendance register and this feature does not create
 * one - adding a field here, even « for later », is what would turn a one-minute pass into a cahier
 * d'appel and a retention obligation.
 *
 * `Clean` is the default state of every student of every statement: the teacher only ever touches
 * the exceptions - three or four cards out of thirty. `OutOfScope` is the state that protects the
 * people the game must not punish - the apprentice in the company, the student on sick leave, the
 * one who arrived in November - and it works by leaving the **denominator**, not by paying points.
 */
enum AttendanceState: string
{
    case Clean = 'clean';
    case NotClean = 'not_clean';
    case OutOfScope = 'out_of_scope';

    /** The order a click walks through: net -> pas net -> hors comptage -> net. */
    public function next(): self
    {
        return match ($this) {
            self::Clean => self::NotClean,
            self::NotClean => self::OutOfScope,
            self::OutOfScope => self::Clean,
        };
    }

    /** Whether the unit counts towards the possible. Out of scope is neutral: it leaves it. */
    public function counts(): bool
    {
        return self::OutOfScope !== $this;
    }

    /** Whether the unit keeps a streak alive. Out of scope does not break one - it is not a fault. */
    public function keepsStreak(): bool
    {
        return self::NotClean !== $this;
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Clean => 'attendanceStateCleanLabel',
            self::NotClean => 'attendanceStateNotCleanLabel',
            self::OutOfScope => 'attendanceStateOutOfScopeLabel',
        };
    }

    public function symbol(): string
    {
        return match ($this) {
            self::Clean => '✓',
            self::NotClean => '✕',
            self::OutOfScope => '–',
        };
    }
}
