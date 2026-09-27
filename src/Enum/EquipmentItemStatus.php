<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where a unit-tracked piece of equipment stands. Always the consequence of its last journal line,
 * never set on its own: changing it is recording a movement.
 *
 * The last three are « hors service »: the piece counts neither as available nor as in use.
 */
enum EquipmentItemStatus: string implements HasBadge
{
    /** In the reserve, ready to replace something - the default of every new piece. */
    case Available = 'available';

    /** In service, in a room when one was named. */
    case InUse = 'in_use';

    /** « Disparu » - until somebody finds it. */
    case Missing = 'missing';

    /** « Hors d'usage » - until it is repaired, or disposed of. */
    case OutOfOrder = 'out_of_order';

    /** « Mis au rebut » - gone for good. Its code is never handed out again. */
    case Disposed = 'disposed';

    public function isInService(): bool
    {
        return self::Available === $this || self::InUse === $this;
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Available => 'equipmentStatusAvailableLabel',
            self::InUse => 'equipmentStatusInUseLabel',
            self::Missing => 'equipmentStatusMissingLabel',
            self::OutOfOrder => 'equipmentStatusOutOfOrderLabel',
            self::Disposed => 'equipmentStatusDisposedLabel',
        };
    }

    /** The tone of the status badge. */
    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Available => BadgeTone::Green,
            self::InUse => BadgeTone::Blue,
            self::Missing => BadgeTone::Red,
            self::OutOfOrder => BadgeTone::Gold,
            self::Disposed => BadgeTone::Gray,
        };
    }
}
