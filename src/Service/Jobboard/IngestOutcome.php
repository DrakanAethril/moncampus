<?php

declare(strict_types=1);

namespace App\Service\Jobboard;

/** What became of one offer of a batch. */
enum IngestOutcome: string
{
    case Created = 'created';
    case Reviewed = 'reviewed';
    case Rejected = 'rejected';

    /**
     * The site is on the blacklist, so the offer was read, understood and dropped.
     *
     * **Not a refusal**, and the distinction is the whole reason it is a fourth case rather than a
     * `JobboardRejection`: a refusal says « cette offre est mauvaise » and is told to the agent
     * line by line, whereas this says « ce site ne nous intéresse pas », which is a decision of the
     * platform and none of the collector's business. The API answer stays silent about it; the
     * history counts it, because a column of offers that evaporated is exactly what an
     * administrator needs to see after blacklisting a site.
     */
    case Blocked = 'blocked';

    public function labelKey(): string
    {
        return match ($this) {
            self::Created => 'jobboardOutcomeCreatedLabel',
            self::Reviewed => 'jobboardOutcomeReviewedLabel',
            self::Rejected => 'jobboardOutcomeRejectedLabel',
            self::Blocked => 'jobboardOutcomeBlockedLabel',
        };
    }
}
