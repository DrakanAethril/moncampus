<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Delivery status of an outgoing mail, fed by the SES events consumed from the "events" queue
 * (App\Command\ConsumeMailEventsCommand).
 *
 * Deliberately without an "opened" state: open tracking is not enabled on the SES configuration
 * sets (a tracking pixel on the recipient, an anti-spam penalty, unreliable data anyway).
 */
enum EmailDeliveryStatus: string
{
    /** Written at send time, before any feedback from SES. */
    case Queued = 'queued';

    /** SES accepted the message (Send event): it says nothing about reception. */
    case Sent = 'sent';

    /** Accepted by the recipient's server (Delivery event) - the only trustworthy status. */
    case Delivered = 'delivered';

    /** Delayed on the recipient's side (full mailbox, saturated server): neither delivered nor failed. */
    case Delayed = 'delayed';

    /** Dead address or permanent refusal (Bounce event). */
    case Bounced = 'bounced';

    /** The recipient flagged the message as spam (Complaint event). */
    case Complained = 'complained';

    /** SES refused the message before sending it (Reject event: a detected virus, for instance). */
    case Rejected = 'rejected';

    public function labelKey(): string
    {
        return match ($this) {
            self::Queued => 'emailStatusQueuedLabel',
            self::Sent => 'emailStatusSentLabel',
            self::Delivered => 'emailStatusDeliveredLabel',
            self::Delayed => 'emailStatusDelayedLabel',
            self::Bounced => 'emailStatusBouncedLabel',
            self::Complained => 'emailStatusComplainedLabel',
            self::Rejected => 'emailStatusRejectedLabel',
        };
    }

    /** The terminal failure states, the ones surfaced so the student fixes the contact. */
    public function isFailure(): bool
    {
        return match ($this) {
            self::Bounced, self::Complained, self::Rejected => true,
            default => false,
        };
    }
}
