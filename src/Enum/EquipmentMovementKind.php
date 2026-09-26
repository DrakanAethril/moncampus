<?php

declare(strict_types=1);

namespace App\Enum;

use App\Service\Equipment\EquipmentCounterDelta;

/**
 * The kinds of line the equipment journal holds.
 *
 * delta() is the one place that says what a line does to the counters of its type: the ledger
 * applies it with the movement, and App\Service\Equipment\EquipmentStockCounter sums it over the
 * whole journal to check them. Two copies of this rule would disagree the day one of them moved,
 * and the nightly pass would then "correct" every counter in the inventory.
 */
enum EquipmentMovementKind: string
{
    /** Received, or entered at creation: straight into the available stock. */
    case Intake = 'intake';

    /** Put into service - « Utilisé ». */
    case Deploy = 'deploy';

    /** Back into the reserve - « Disponible » again. */
    case Return = 'return';

    public function delta(int $quantity): EquipmentCounterDelta
    {
        return match ($this) {
            self::Intake => new EquipmentCounterDelta(available: $quantity),
            self::Deploy => new EquipmentCounterDelta(available: -$quantity, inUse: $quantity),
            self::Return => new EquipmentCounterDelta(available: $quantity, inUse: -$quantity),
        };
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Intake => 'equipmentMovementIntakeLabel',
            self::Deploy => 'equipmentMovementDeployLabel',
            self::Return => 'equipmentMovementReturnLabel',
        };
    }
}
