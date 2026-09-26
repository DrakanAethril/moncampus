<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where a unit-tracked piece of equipment stands. Always the consequence of its last journal line,
 * never set on its own: changing it is recording a movement.
 */
enum EquipmentItemStatus: string
{
    /** In the reserve, ready to replace something - the default of every new piece. */
    case Available = 'available';

    /** In service, in a room when one was named. */
    case InUse = 'in_use';

    public function labelKey(): string
    {
        return match ($this) {
            self::Available => 'equipmentStatusAvailableLabel',
            self::InUse => 'equipmentStatusInUseLabel',
        };
    }

    /** The cm-badge modifier of the status pill. */
    public function badgeModifier(): string
    {
        return match ($this) {
            self::Available => 'green',
            self::InUse => 'blue',
        };
    }
}
