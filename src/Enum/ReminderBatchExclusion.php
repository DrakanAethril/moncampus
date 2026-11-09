<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Why a line was set aside at launch (R9): visible in the batch, so that « 39 waited, 37 mails
 * left » explains itself.
 */
enum ReminderBatchExclusion: string
{
    /** Nobody to write to: no contact address, nor a secondary tutor's on a tutor step. */
    case NoAddress = 'no_address';
    /** Already waiting for this very step in a running or paused batch (R3). */
    case InActiveBatch = 'in_active_batch';

    public function labelKey(): string
    {
        return match ($this) {
            self::NoAddress => 'reminderBatchExclusionNoAddressLabel',
            self::InActiveBatch => 'reminderBatchExclusionInActiveBatchLabel',
        };
    }
}
