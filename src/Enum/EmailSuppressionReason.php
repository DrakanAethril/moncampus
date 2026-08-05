<?php

namespace App\Enum;

/**
 * Why the platform stopped writing to an address
 * (design_handoff_courrier_ecole_infra §6).
 *
 * Only the two SES events that damage a sending domain's reputation land here. A soft bounce - a
 * full mailbox, a server having a bad day - does not: the address is alive, and suppressing it would
 * cost a student a real contact over a transient failure.
 */
enum EmailSuppressionReason: string
{
    /** Permanent bounce: the address does not exist, or the domain refuses it for good. */
    case HardBounce = 'hard_bounce';

    /** The recipient marked the mail as spam. Writing again is the surest way to be blocked. */
    case Complaint = 'complaint';

    public function labelKey(): string
    {
        return match ($this) {
            self::HardBounce => 'emailSuppressionHardBounceLabel',
            self::Complaint => 'emailSuppressionComplaintLabel',
        };
    }
}
