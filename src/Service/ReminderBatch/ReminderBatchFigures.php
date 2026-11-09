<?php

declare(strict_types=1);

namespace App\Service\ReminderBatch;

use App\Enum\ReminderBatchItemStatus;

/**
 * A batch's figures, read from the GROUP BY of its lines - never stored. Only what the platform
 * knows: what left (accepted by SES), what did not and why.
 */
final readonly class ReminderBatchFigures
{
    public function __construct(
        public int $sent,
        public int $alreadyDone,
        public int $notConcerned,
        public int $failed,
        public int $uncertain,
        public int $waiting,
        public int $excluded,
        public int $cancelled,
    ) {
    }

    /**
     * @param array<string, int> $countsByStatus status value => lines
     */
    public static function fromCounts(array $countsByStatus): self
    {
        $count = static fn (ReminderBatchItemStatus $status): int => $countsByStatus[$status->value] ?? 0;

        return new self(
            $count(ReminderBatchItemStatus::Sent),
            $count(ReminderBatchItemStatus::AlreadyDone),
            $count(ReminderBatchItemStatus::NotConcerned),
            $count(ReminderBatchItemStatus::Failed),
            $count(ReminderBatchItemStatus::Uncertain),
            $count(ReminderBatchItemStatus::Pending) + $count(ReminderBatchItemStatus::Sending),
            $count(ReminderBatchItemStatus::Excluded),
            $count(ReminderBatchItemStatus::Cancelled),
        );
    }

    /** Every line of the batch, set-aside ones included. */
    public function total(): int
    {
        return $this->sent + $this->alreadyDone + $this->notConcerned + $this->failed + $this->uncertain + $this->waiting + $this->excluded + $this->cancelled;
    }

    /** The lines the batch had to deal with - the set-aside ones never were. */
    public function toHandle(): int
    {
        return $this->total() - $this->excluded;
    }

    public function handled(): int
    {
        return $this->toHandle() - $this->waiting - $this->cancelled;
    }

    /** Percentages of the lines to handle, for the progress bar's segments. */
    public function share(int $lines): float
    {
        return 0 === $this->toHandle() ? 0.0 : round(100 * $lines / $this->toHandle(), 1);
    }
}
