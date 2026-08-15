<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Which side of a threshold a grade has to fall on, for the GradeValue leaf.
 *
 * Two strict comparisons rather than a min/max pair as the quiz score uses: a grade condition is
 * written one bound at a time ("inférieure à 15"), and a range is two leaves in "toutes les
 * conditions" mode - which is what lets "> 10 et < 15" be read, removed and re-read one half at a
 * time on the teacher's screen.
 *
 * The value is stored inside the access_condition JSON, so renaming a case means migrating rows.
 */
enum AccessConditionComparison: string
{
    case Below = 'below';
    case Above = 'above';

    /** Strict on both sides: "inférieure à 15" excludes 15, and a teacher who means 15 writes 15. */
    public function holds(float $grade, float $threshold): bool
    {
        return match ($this) {
            self::Below => $grade < $threshold,
            self::Above => $grade > $threshold,
        };
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Below => 'accessConditionComparisonBelowLabel',
            self::Above => 'accessConditionComparisonAboveLabel',
        };
    }

    public function symbol(): string
    {
        return match ($this) {
            self::Below => '<',
            self::Above => '>',
        };
    }
}
