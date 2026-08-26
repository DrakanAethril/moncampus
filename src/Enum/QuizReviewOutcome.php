<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a teacher concluded after looking at a flagged attempt.
 *
 * This is the only thing in the whole device that asserts something about somebody - and it is
 * signed, dated and written by a person. The application states facts; a human states this.
 *
 * Neither value touches a mark. « Retenu » means the case was passed on to the establishment, whose
 * business the disciplinary side is; « écarté » means there was nothing in it, and the attempt stops
 * coming back on the list.
 */
enum QuizReviewOutcome: string
{
    case Dismissed = 'dismissed';
    case Upheld = 'upheld';

    public function labelKey(): string
    {
        return match ($this) {
            self::Dismissed => 'quizReviewOutcomeDismissedLabel',
            self::Upheld => 'quizReviewOutcomeUpheldLabel',
        };
    }
}
