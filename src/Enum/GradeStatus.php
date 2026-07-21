<?php

namespace App\Enum;

/**
 * The "kind" of a gradebook cell (design's cellVal()/interpret() - a free-text cell parses into
 * one of these). Only Normal counts toward App\Service\EvaluationAverageCalculator's averages -
 * Excluded still carries a real Grade::$value (the "(12)" parenthesised-note display, entered but
 * deliberately not counted) while Absent/NotEvaluated/NotTested never do.
 */
enum GradeStatus: string
{
    case Normal = 'normal';
    case Absent = 'absent';
    case NotEvaluated = 'not_evaluated';
    case NotTested = 'not_tested';
    case Excluded = 'excluded';

    public function countsTowardAverage(): bool
    {
        return self::Normal === $this;
    }

    public function shortLabelKey(): string
    {
        return match ($this) {
            self::Normal => 'gradeStatusNormalShortLabel',
            self::Absent => 'gradeStatusAbsentShortLabel',
            self::NotEvaluated => 'gradeStatusNotEvaluatedShortLabel',
            self::NotTested => 'gradeStatusNotTestedShortLabel',
            self::Excluded => 'gradeStatusExcludedShortLabel',
        };
    }
}
