<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What the student gets back from their self-assessment (design_handoff_carnet_de_notes,
 * PROMPT_MODIFICATIONS §9, screen 5a).
 *
 * Comparison opens the comparison screen (5c) once the estimate is submitted AND the teacher's
 * grading has become visible; Alone keeps the grading for the teacher alone - the student simply sees
 * that their self-assessment has been sent. It is a per-assignment choice, not a setting of the
 * evaluation: the same evaluation can be self-assessed blind during the year and in comparison later.
 */
enum SelfAssessmentFeedback: string
{
    case Comparison = 'comparison';
    case Alone = 'alone';

    public function labelKey(): string
    {
        return match ($this) {
            self::Comparison => 'selfAssessmentFeedbackComparisonLabel',
            self::Alone => 'selfAssessmentFeedbackAloneLabel',
        };
    }

    public function hintKey(): string
    {
        return match ($this) {
            self::Comparison => 'selfAssessmentFeedbackComparisonHint',
            self::Alone => 'selfAssessmentFeedbackAloneHint',
        };
    }
}
