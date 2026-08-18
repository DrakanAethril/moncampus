<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where an address stands in its life.
 *
 * The four cases are a sequence, and each transition has a reason the others do not:
 *
 *   reserved  - step 2 of the creation wizard took it, in a transaction, choosing the first free
 *               one according to all three sources (registry, Proxmox, external declarations).
 *   assigned  - the creation call was accepted; the address now belongs to a machine that exists.
 *   confirmed - a machine actually answered at it. This is the only state that is evidence rather
 *               than intention.
 *   released  - back on offer. Reached when a creation fails (immediately, or the range empties
 *               itself one failed attempt at a time), when an abandoned reservation ages out, or
 *               when an administrator frees an address the scan reported as orphaned.
 *
 * A released row is kept rather than deleted: it is the history of who held what, and the
 * uniqueness index is written so it stops colliding the moment it is released.
 */
enum IpAllocationStatus: string
{
    case Reserved = 'reserved';
    case Assigned = 'assigned';
    case Confirmed = 'confirmed';
    case Released = 'released';

    public function labelKey(): string
    {
        return match ($this) {
            self::Reserved => 'ipAllocationReservedLabel',
            self::Assigned => 'ipAllocationAssignedLabel',
            self::Confirmed => 'ipAllocationConfirmedLabel',
            self::Released => 'ipAllocationReleasedLabel',
        };
    }

    public function badgeModifier(): string
    {
        return match ($this) {
            self::Reserved => 'gold',
            self::Assigned => 'blue',
            self::Confirmed => 'green',
            self::Released => 'gray',
        };
    }

    /** Whether the address is still spoken for - the three states that occupy a slot. */
    public function isLive(): bool
    {
        return self::Released !== $this;
    }
}
