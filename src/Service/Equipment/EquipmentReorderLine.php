<?php

declare(strict_types=1);

namespace App\Service\Equipment;

/**
 * One type's line of « À commander » - see App\Service\Equipment\EquipmentReorderPlanner.
 */
final readonly class EquipmentReorderLine
{
    public function __construct(
        /** Whether the list puts it first: nothing left, under the threshold, or running out within a month. */
        public bool $needsOrder,
        /** Under the threshold, but what is already ordered brings it back above. */
        public bool $coveredByOrder,
        /** Back to the target stock, net of what is ordered; null when the type names no target. */
        public ?int $suggested,
        /** Spares taken per month, over the last six. */
        public float $monthlyConsumption,
        /** When the reserve runs dry at that pace; null when nothing is taken. */
        public ?\DateTimeImmutable $runsOutOn,
    ) {
    }
}
