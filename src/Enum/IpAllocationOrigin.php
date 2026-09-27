<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where an entry in the registry came from - which is a different question from what state it is
 * in, and the column the address screen leans on hardest.
 *
 *   declared   - MonCampus handed this address out. Its MAC carries the platform's own prefix.
 *   discovered - the Proxmox scan found a machine already using it. Somebody created that machine
 *                by hand, and its MAC is still Proxmox's own (BC:24:11:…). Adopting it is what
 *                makes the registry stop lying about that address being free.
 *   external   - an administrator declared it by hand for something that is not a Proxmox guest at
 *                all: a printer, a switch, an access point. These must never be offered, and the
 *                scan must never report them as orphaned when it fails to find a machine holding
 *                them - there is no machine, and there never was.
 */
enum IpAllocationOrigin: string implements HasBadge
{
    case Declared = 'declared';
    case Discovered = 'discovered';
    case External = 'external';

    public function labelKey(): string
    {
        return match ($this) {
            self::Declared => 'ipOriginDeclaredLabel',
            self::Discovered => 'ipOriginDiscoveredLabel',
            self::External => 'ipOriginExternalLabel',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Declared => BadgeTone::Gray,
            self::Discovered => BadgeTone::Blue,
            self::External => BadgeTone::Teal,
        };
    }
}
