<?php

declare(strict_types=1);

namespace App\Service\ReminderBatch;

use App\Entity\InternshipReminder;

/**
 * A batch line's mail, accepted by SES: the reminder it logged, the address it went to, whether the
 * secondary tutor was in copy, and SES's identifier for it.
 */
final readonly class ReminderDelivery
{
    public function __construct(
        public InternshipReminder $reminder,
        public string $recipientEmail,
        public bool $copiedSecondaryTutor,
        public ?string $providerMessageId,
    ) {
    }
}
