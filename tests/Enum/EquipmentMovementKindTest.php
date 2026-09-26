<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\EquipmentItemStatus;
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

    /** An incident takes the pieces out of whichever count they were in - the reserve, or the rooms. */
    public function testAnIncidentLeavesTheCountItCameFrom(): void
    {
        self::assertEquals(new EquipmentCounterDelta(available: -2), EquipmentMovementKind::Missing->delta(2, EquipmentItemStatus::Available));
        self::assertEquals(new EquipmentCounterDelta(inUse: -1), EquipmentMovementKind::Missing->delta(1, EquipmentItemStatus::InUse));
        self::assertEquals(new EquipmentCounterDelta(inUse: -3), EquipmentMovementKind::OutOfOrder->delta(3, EquipmentItemStatus::InUse));
    }

    public function testAnIncidentWithoutItsOriginIsAProgrammingError(): void
    {
        $this->expectException(\LogicException::class);
        EquipmentMovementKind::Missing->delta(1);
    }

    /** Found or repaired, a piece comes back as a spare - « Disponible », whatever it was before. */
    public function testFoundAndRepairedComeBackAvailable(): void
    {
        self::assertEquals(new EquipmentCounterDelta(available: 1), EquipmentMovementKind::Found->delta(1));
        self::assertEquals(new EquipmentCounterDelta(available: 4), EquipmentMovementKind::Repaired->delta(4));
    }

    /** Disposing of a piece already out of order moves no counter: it had left them already. */
    public function testDisposalMovesNoCounter(): void
    {
        self::assertTrue(EquipmentMovementKind::Disposed->delta(1)->isZero());
    }

    public function testOnlyTheTwoLossesAreIncidents(): void
    {
        $incidents = array_values(array_filter(EquipmentMovementKind::cases(), static fn (EquipmentMovementKind $kind): bool => $kind->isIncident()));

        self::assertSame([EquipmentMovementKind::Missing, EquipmentMovementKind::OutOfOrder], $incidents);
        self::assertSame(EquipmentMovementKind::Missing, EquipmentMovementKind::Found->resolves());
        self::assertSame(EquipmentMovementKind::OutOfOrder, EquipmentMovementKind::Repaired->resolves());
    }

    public function testDeltasAddUp(): void
    {
        $total = EquipmentCounterDelta::zero()
            ->plus(EquipmentMovementKind::Intake->delta(10))
            ->plus(EquipmentMovementKind::Deploy->delta(4))
            ->plus(EquipmentMovementKind::Return->delta(1))
            ->plus(EquipmentMovementKind::OutOfOrder->delta(1, EquipmentItemStatus::InUse))
            ->plus(EquipmentMovementKind::Repaired->delta(1));

        self::assertEquals(new EquipmentCounterDelta(available: 8, inUse: 2), $total);
    }
}
