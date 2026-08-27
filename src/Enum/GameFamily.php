<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The four families a point falls into (design/validated/gamification.md §2).
 *
 * A family is not a category of screen: it is a **denominator**. Each one is scored on its own rate
 * - points earned over points it was possible to earn for that student on that period - and the
 * weighted mean of the four rates is the index. That is why a family with no data can simply leave
 * the calculation and hand its weight to the others (App\Service\Game\GameScoreCalculator).
 *
 * The weights below are the starting values; they live in
 * App\Entity\GameProgramSettings and are the real barème (§2, "les pondérations sont le vrai
 * barème"). Nothing else in this enum is configurable.
 */
enum GameFamily: string
{
    case Attendance = 'attendance';
    case Work = 'work';
    case Engagement = 'engagement';
    case Recognition = 'recognition';

    /** The starting weight, out of 100 across the four. */
    public function defaultWeight(): int
    {
        return match ($this) {
            self::Attendance, self::Work => 30,
            self::Engagement => 25,
            self::Recognition => 15,
        };
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Attendance => 'gameFamilyAttendanceLabel',
            self::Work => 'gameFamilyWorkLabel',
            self::Engagement => 'gameFamilyEngagementLabel',
            self::Recognition => 'gameFamilyRecognitionLabel',
        };
    }

    /**
     * Whether the denominator is a flat cap identical for everybody rather than something counted
     * from what happened to this student.
     *
     * Volunteering has no denominator of its own - the occasion is the same for everybody, only the
     * decision to take it differs - and every student sits the same class council. Attendance and
     * work are counted instead, which is what protects the apprentice and the newcomer.
     */
    public function hasFlatPossible(): bool
    {
        return \in_array($this, [self::Engagement, self::Recognition], true);
    }
}
