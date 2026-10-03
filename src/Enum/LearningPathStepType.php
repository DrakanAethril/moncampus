<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a step of a learning path is (design/validated/cours-en-ligne.md, §10): a course to read, or
 * a validation quiz. A path is a sequence of courses; a quiz is put wherever its author wants one -
 * between two courses, after several, at the end, or nowhere.
 *
 * The value is stored, so renaming a case means migrating rows.
 */
enum LearningPathStepType: string implements HasBadge
{
    case Course = 'course';
    case Quiz = 'quiz';

    public function labelKey(): string
    {
        return match ($this) {
            self::Course => 'learningPathStepCourseLabel',
            self::Quiz => 'learningPathStepQuizLabel',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Course => BadgeTone::Blue,
            self::Quiz => BadgeTone::Gold,
        };
    }
}
