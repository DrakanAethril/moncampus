<?php

declare(strict_types=1);

namespace App\Service\Equipment;

/**
 * The rule of « À commander »: which types need re-ordering, how many, and when the reserve runs
 * dry at the current pace.
 *
 * - **Consumption** is what left the reserve over the last six months: pieces put into service and
 *   pieces lost straight from the reserve (App\Repository\EquipmentMovementRepository::consumptionSince()).
 *   Six months rather than the whole history, so a type whose use changed is read on its present.
 * - **What is ordered counts as coming.** A type under its threshold whose order in progress brings it
 *   back above is not flagged again - suggesting the same order twice is what the « en commande »
 *   state exists to prevent.
 * - **The suggestion goes back to the target stock**, net of what is available and ordered. A type
 *   with no target gets no figure rather than an invented one.
 * - Running out within a month with nothing ordered flags a type even above its threshold.
 */
final class EquipmentReorderPlanner
{
    /** The window consumption is read over: six months, which is what « par mois » divides by. */
    public const int WINDOW_MONTHS = 6;
    public const int WINDOW_DAYS = 182;
    private const int SOON_DAYS = 31;

    public function plan(int $available, int $onOrder, ?int $threshold, ?int $target, int $consumedInWindow, \DateTimeImmutable $today): EquipmentReorderLine
    {
        $perDay = $consumedInWindow / self::WINDOW_DAYS;
        $runsOutOn = null;

        if ($perDay > 0) {
            $runsOutOn = $today->setTime(0, 0)->modify(\sprintf('+%d days', (int) floor(max(0, $available) / $perDay)));
        }

        $coming = $available + $onOrder;
        $underThreshold = $available <= 0 || (null !== $threshold && $available <= $threshold);
        $stillUnder = $coming <= 0 || (null !== $threshold && $coming <= $threshold);
        $runsOutSoon = null !== $runsOutOn && 0 === $onOrder && $runsOutOn <= $today->modify(\sprintf('+%d days', self::SOON_DAYS));

        return new EquipmentReorderLine(
            needsOrder: $stillUnder || $runsOutSoon,
            coveredByOrder: $underThreshold && !$stillUnder,
            suggested: null !== $target ? max(0, $target - $coming) : null,
            monthlyConsumption: $consumedInWindow / self::WINDOW_MONTHS,
            runsOutOn: $runsOutOn,
        );
    }
}
