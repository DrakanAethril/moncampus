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
 *
 * The two **incidents** say what happened to the piece - it is gone (Missing), or it no longer
 * works (OutOfOrder) - and carry a cause saying why; they take the pieces out of the count they
 * were in, which is why they also carry their origin. Found and Repaired answer one incident each
 * and bring the pieces back as spares. Disposed is the end of life of a piece already out of order:
 * not an incident, and it moves no counter.
 */
enum EquipmentMovementKind: string
{
    /** Received, or entered at creation: straight into the available stock. */
    case Intake = 'intake';

    /** Put into service - « Utilisé ». */
    case Deploy = 'deploy';

    /** Back into the reserve - « Disponible » again. */
    case Return = 'return';

    /** « Disparu » - the piece is no longer there. */
    case Missing = 'missing';

    /** « Hors d'usage » - the piece is there and no longer works. */
    case OutOfOrder = 'out_of_order';

    /** « Retrouvé » - answers a Missing. */
    case Found = 'found';

    /** « Réparé » - answers an OutOfOrder. */
    case Repaired = 'repaired';

    /** « Mis au rebut » - a piece out of order leaves the inventory for good. */
    case Disposed = 'disposed';

    /**
     * @param EquipmentItemStatus|null $origin the count an incident takes its pieces from -
     *                                         Available or InUse; required for an incident, ignored otherwise
     */
    public function delta(int $quantity, ?EquipmentItemStatus $origin = null): EquipmentCounterDelta
    {
        return match ($this) {
            self::Intake, self::Found, self::Repaired => new EquipmentCounterDelta(available: $quantity),
            self::Deploy => new EquipmentCounterDelta(available: -$quantity, inUse: $quantity),
            self::Return => new EquipmentCounterDelta(available: $quantity, inUse: -$quantity),
            self::Missing, self::OutOfOrder => match ($origin) {
                EquipmentItemStatus::Available => new EquipmentCounterDelta(available: -$quantity),
                EquipmentItemStatus::InUse => new EquipmentCounterDelta(inUse: -$quantity),
                default => throw new \LogicException('An incident names the count its pieces came from.'),
            },
            self::Disposed => EquipmentCounterDelta::zero(),
        };
    }

    public function isIncident(): bool
    {
        return self::Missing === $this || self::OutOfOrder === $this;
    }

    /** The incident this line answers, for Found and Repaired. */
    public function resolves(): ?self
    {
        return match ($this) {
            self::Found => self::Missing,
            self::Repaired => self::OutOfOrder,
            default => null,
        };
    }

    /** What a piece is after this incident. */
    public function statusAfter(): ?EquipmentItemStatus
    {
        return match ($this) {
            self::Missing => EquipmentItemStatus::Missing,
            self::OutOfOrder => EquipmentItemStatus::OutOfOrder,
            self::Found, self::Repaired => EquipmentItemStatus::Available,
            self::Disposed => EquipmentItemStatus::Disposed,
            default => null,
        };
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Intake => 'equipmentMovementIntakeLabel',
            self::Deploy => 'equipmentMovementDeployLabel',
            self::Return => 'equipmentMovementReturnLabel',
            self::Missing => 'equipmentMovementMissingLabel',
            self::OutOfOrder => 'equipmentMovementOutOfOrderLabel',
            self::Found => 'equipmentMovementFoundLabel',
            self::Repaired => 'equipmentMovementRepairedLabel',
            self::Disposed => 'equipmentMovementDisposedLabel',
        };
    }
}
