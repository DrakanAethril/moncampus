<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\EquipmentMovementKind;
use App\Service\Equipment\EquipmentCounterDelta;
use PHPUnit\Framework\TestCase;

/**
 * What each line of the equipment journal does to the counters of its type.
 *
 * This is the single rule both paths read: the live update applied with the movement, and the
 * recomputation that sums the journal again. If they each carried their own copy, the nightly pass
 * would "correct" every counter the day one of them moved.
 */
class EquipmentMovementKindTest extends TestCase
{
    public function testAnIntakeAddsToTheAvailableStock(): void
    {
        self::assertEquals(new EquipmentCounterDelta(available: 3), EquipmentMovementKind::Intake->delta(3));
    }

    public function testDeployingMovesFromAvailableToInUse(): void
    {
        self::assertEquals(new EquipmentCounterDelta(available: -2, inUse: 2), EquipmentMovementKind::Deploy->delta(2));
    }

    public function testReturningMovesFromInUseToAvailable(): void
    {
        self::assertEquals(new EquipmentCounterDelta(available: 1, inUse: -1), EquipmentMovementKind::Return->delta(1));
    }

    public function testDeltasAddUp(): void
    {
        $total = EquipmentCounterDelta::zero()
            ->plus(EquipmentMovementKind::Intake->delta(10))
            ->plus(EquipmentMovementKind::Deploy->delta(4))
            ->plus(EquipmentMovementKind::Return->delta(1));

        self::assertEquals(new EquipmentCounterDelta(available: 7, inUse: 3), $total);
    }
}
