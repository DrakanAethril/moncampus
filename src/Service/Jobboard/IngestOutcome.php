<?php

declare(strict_types=1);

namespace App\Service\Jobboard;

/** What became of one offer of a batch. */
enum IngestOutcome: string
{
    case Created = 'created';
    case Reviewed = 'reviewed';
    case Rejected = 'rejected';

    public function labelKey(): string
    {
        return match ($this) {
            self::Created => 'jobboardOutcomeCreatedLabel',
            self::Reviewed => 'jobboardOutcomeReviewedLabel',
            self::Rejected => 'jobboardOutcomeRejectedLabel',
        };
    }
}
