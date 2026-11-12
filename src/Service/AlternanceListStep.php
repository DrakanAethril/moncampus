<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\ReminderBatch\ReminderBatchTarget;

/**
 * One choice of the UFA list's « Étape » filter: where a live alternance stands now, as
 * AlternancePeriodStatusResolver::resolveCurrentStep() says it - one of the three engagement
 * signatures, the tutor's, the alternant's or the supervisor's part of a bilan, or nothing left
 * to do.
 *
 * A bilan is named by its rank among its formation's active bilans, counted from 1 - the reminder
 * batches' way (ReminderBatchTarget), which is what lets one choice cover « the 2nd bilan » of
 * formations whose bilans have different names, and what lets the staff home banner send its
 * busiest target straight here.
 *
 * `key()` is how the choice travels in the query string (« tutor:2 », « engagement_center »).
 */
final readonly class AlternanceListStep
{
    public const string CLOSED = AlternanceStepStatus::STEP_CLOSED;

    private const array ENGAGEMENT_STEPS = [
        AlternanceStepStatus::STEP_ENGAGEMENT_TUTOR,
        AlternanceStepStatus::STEP_ENGAGEMENT_STUDENT,
        AlternanceStepStatus::STEP_ENGAGEMENT_CENTER,
    ];

    private const array PERIOD_STEPS = [
        AlternanceStepStatus::STEP_TUTOR,
        AlternanceStepStatus::STEP_STUDENT,
        AlternanceStepStatus::STEP_SUPERVISOR,
    ];

    private function __construct(
        public string $step,
        public ?int $rank,
    ) {
    }

    public static function of(string $statusStep, ?int $rank = null): ?self
    {
        if (\in_array($statusStep, self::PERIOD_STEPS, true)) {
            return null !== $rank && $rank > 0 ? new self($statusStep, $rank) : null;
        }

        if (self::CLOSED === $statusStep || \in_array($statusStep, self::ENGAGEMENT_STEPS, true)) {
            return null === $rank ? new self($statusStep, null) : null;
        }

        return null;
    }

    public static function fromKey(string $key): ?self
    {
        [$step, $rank] = array_pad(explode(':', trim($key), 2), 2, null);
        if (null === $rank) {
            return self::of((string) $step);
        }

        return ctype_digit($rank) ? self::of((string) $step, (int) $rank) : null;
    }

    // The banner of the staff home names a reminder batch's target; this is the same stage of
    // the livret, read as the list's filter.
    public static function forTarget(ReminderBatchTarget $target): ?self
    {
        return self::of($target->step->statusStep(), $target->rank);
    }

    /**
     * Every choice the filter offers, in the order the livret is filled in: the engagement, then
     * bilan by bilan up to $maxRank, then « Rien en attente ».
     *
     * @return list<self>
     */
    public static function options(int $maxRank): array
    {
        $options = array_map(static fn (string $step): self => new self($step, null), self::ENGAGEMENT_STEPS);
        for ($rank = 1; $rank <= $maxRank; ++$rank) {
            foreach (self::PERIOD_STEPS as $step) {
                $options[] = new self($step, $rank);
            }
        }
        $options[] = new self(self::CLOSED, null);

        return $options;
    }

    public function key(): string
    {
        return null === $this->rank ? $this->step : $this->step.':'.$this->rank;
    }

    /** Takes %rank% for the three bilan steps. */
    public function labelKey(): string
    {
        return match ($this->step) {
            AlternanceStepStatus::STEP_ENGAGEMENT_TUTOR => 'ufaAlternanceStepEngagementTutorLabel',
            AlternanceStepStatus::STEP_ENGAGEMENT_STUDENT => 'ufaAlternanceStepEngagementStudentLabel',
            AlternanceStepStatus::STEP_ENGAGEMENT_CENTER => 'ufaAlternanceStepEngagementCenterLabel',
            AlternanceStepStatus::STEP_TUTOR => 'ufaAlternanceStepPeriodTutorLabel',
            AlternanceStepStatus::STEP_STUDENT => 'ufaAlternanceStepPeriodStudentLabel',
            AlternanceStepStatus::STEP_SUPERVISOR => 'ufaAlternanceStepPeriodSupervisorLabel',
            default => 'ufaAlternanceStepClosedLabel',
        };
    }
}
