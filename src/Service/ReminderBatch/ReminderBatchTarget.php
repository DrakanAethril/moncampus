<?php

declare(strict_types=1);

namespace App\Service\ReminderBatch;

use App\Enum\ReminderBatchStep;

/**
 * What a batch chases, across formations: a step, and for a bilan its rank - « the 2nd bilan of
 * each formation, whatever its name » (Q2). The rank is the bilan's place among the formation's
 * active bilans, by start date, counted from 1.
 *
 * `key()` is how the target travels in a form and a URL (« period_tutor:2 »).
 */
final readonly class ReminderBatchTarget
{
    public function __construct(
        public ReminderBatchStep $step,
        public ?int $rank,
    ) {
        if ($step->isPeriod() !== (null !== $rank && $rank > 0)) {
            throw new \InvalidArgumentException('A bilan step takes a rank from 1, an engagement step none.');
        }
    }

    public static function fromKey(string $key): ?self
    {
        [$stepValue, $rankValue] = array_pad(explode(':', $key, 2), 2, null);
        $step = ReminderBatchStep::tryFrom((string) $stepValue);
        if (null === $step) {
            return null;
        }

        if (!$step->isPeriod()) {
            return null === $rankValue ? new self($step, null) : null;
        }

        return null !== $rankValue && ctype_digit($rankValue) && (int) $rankValue > 0 ? new self($step, (int) $rankValue) : null;
    }

    public function key(): string
    {
        return null === $this->rank ? $this->step->value : $this->step->value.':'.$this->rank;
    }

    /**
     * The order a list of targets is offered in: the engagement first, then bilan by bilan, the
     * tutor before the alternant - the order the livret is filled in.
     */
    public function sortKey(): string
    {
        $stepOrder = match ($this->step) {
            ReminderBatchStep::EngagementTutor => 0,
            ReminderBatchStep::EngagementStudent => 1,
            ReminderBatchStep::PeriodTutor => 0,
            ReminderBatchStep::PeriodStudent => 1,
        };

        return \sprintf('%03d-%d', $this->rank ?? 0, $stepOrder);
    }
}
