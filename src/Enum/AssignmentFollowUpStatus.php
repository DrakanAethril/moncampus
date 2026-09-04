<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where one student stands on one assignment, as the teacher's follow-up screen reads it - never
 * persisted, always derived by App\Service\AssignmentFollowUpBoard from whatever the nature accepts
 * as proof.
 *
 * Deliberately a second enum rather than a fourth case on AssignmentSubmissionStatus: that one
 * answers "has this deposit arrived", and three of its four callers ask exactly that. What is added
 * here belongs to natures that never carry a deposit - a quiz answered below the teacher's
 * threshold is neither handed in nor missing.
 *
 * The wording is the nature's, not the status's (App\Enum\AssignmentNature::followUp*LabelKey): a
 * quiz is « Répondu » where a submission is « Rendu », and one vocabulary for both is how a screen
 * comes to announce « Non rendu » to twenty-two students who answered.
 */
enum AssignmentFollowUpStatus: string
{
    case Done = 'done';
    case Late = 'late';
    case Insufficient = 'insufficient';
    case Pending = 'pending';

    public function badgeClass(): string
    {
        return match ($this) {
            self::Done => 'bg-green-lt',
            self::Late => 'bg-yellow-lt',
            self::Insufficient => 'bg-orange-lt',
            self::Pending => 'bg-secondary-lt',
        };
    }
}
