<?php

namespace App\Enum;

// Never persisted - purely a derived, per-student display value computed by
// App\Controller\ProgramAssignmentController from an audience list and existing
// AssignmentSubmission rows (a student either has no submission, an on-time one, or a late one).
enum AssignmentSubmissionStatus: string
{
    case Submitted = 'submitted';
    case Late = 'late';
    case Missing = 'missing';

    public function labelKey(): string
    {
        return match ($this) {
            self::Submitted => 'assignmentSubmissionStatusSubmittedLabel',
            self::Late => 'assignmentSubmissionStatusLateLabel',
            self::Missing => 'assignmentSubmissionStatusMissingLabel',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Submitted => 'bg-green-lt',
            self::Late => 'bg-yellow-lt',
            self::Missing => 'bg-secondary-lt',
        };
    }
}
