<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * One line of a reminder batch - one alternance, at the batch's step.
 *
 * Only what the platform actually knows: « Envoyée » means SES accepted the mail, nothing about
 * delivery or reading, and nothing about an answer (a signature cannot be put down to a mail).
 */
enum ReminderBatchItemStatus: string implements HasBadge
{
    /** Set aside at launch, with its reason (ReminderBatchExclusion). Never sent. */
    case Excluded = 'excluded';
    case Pending = 'pending';
    /** Claimed by a pass and written before the SES call - see ReminderBatchSender. */
    case Sending = 'sending';
    case Sent = 'sent';
    /** Read again just before sending: the person had done their part in between. No mail. */
    case AlreadyDone = 'already_done';
    /** Read again just before sending: the alternance ended, or the bilan was switched off. */
    case NotConcerned = 'not_concerned';
    case Failed = 'failed';
    /** A pass died between « En envoi » and SES's answer: nobody knows, and nothing resends it. */
    case Uncertain = 'uncertain';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public static function waiting(): array
    {
        return [self::Pending, self::Sending];
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Excluded => 'reminderBatchItemExcludedLabel',
            self::Pending => 'reminderBatchItemPendingLabel',
            self::Sending => 'reminderBatchItemSendingLabel',
            self::Sent => 'reminderBatchItemSentLabel',
            self::AlreadyDone => 'reminderBatchItemAlreadyDoneLabel',
            self::NotConcerned => 'reminderBatchItemNotConcernedLabel',
            self::Failed => 'reminderBatchItemFailedLabel',
            self::Uncertain => 'reminderBatchItemUncertainLabel',
            self::Cancelled => 'reminderBatchItemCancelledLabel',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Sent => BadgeTone::Green,
            self::AlreadyDone => BadgeTone::Teal,
            self::Failed => BadgeTone::Red,
            self::Uncertain => BadgeTone::Gold,
            self::Pending, self::Sending => BadgeTone::Blue,
            self::Excluded, self::NotConcerned, self::Cancelled => BadgeTone::Gray,
        };
    }
}
