<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What the gradebook must be told about a student who never did the work, when a travail is turned
 * into an evaluation (« Convertir en note »).
 *
 * The three cases are not interchangeable and none of them is a sane default, which is why the
 * modal asks for every one of them by name: a zero is a mark and drags an average down, an absence
 * is a fact about the day, and « non évalué » says the student is simply outside this evaluation.
 * Guessing here would silently invent a mark nobody entered.
 */
enum AssignmentMissingGradeChoice: string
{
    case Zero = 'zero';
    case Absent = 'absent';
    case NotEvaluated = 'not_evaluated';

    public function labelKey(): string
    {
        return match ($this) {
            self::Zero => 'assignmentGradeConversionMissingZeroLabel',
            self::Absent => 'assignmentGradeConversionMissingAbsentLabel',
            self::NotEvaluated => 'assignmentGradeConversionMissingNotEvaluatedLabel',
        };
    }

    /** The gradebook status it writes - a zero is a real mark, hence GradeStatus::Normal. */
    public function gradeStatus(): GradeStatus
    {
        return match ($this) {
            self::Zero => GradeStatus::Normal,
            self::Absent => GradeStatus::Absent,
            self::NotEvaluated => GradeStatus::NotEvaluated,
        };
    }

    /** And the value that goes with it: only a zero carries one. */
    public function gradeValue(): ?float
    {
        return self::Zero === $this ? 0.0 : null;
    }
}
