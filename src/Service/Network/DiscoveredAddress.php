<?php

declare(strict_types=1);

namespace App\Service\Network;

/**
 * One address the Proxmox scan actually found, and the machine carrying it.
 *
 * Plain values rather than entities, because this is what crosses the boundary between "what the
 * hypervisor says" and "what the registry believes" - and App\Service\Network\AddressReconciler,
 * which compares the two, is pure and must stay testable without a database.
 */
final readonly class DiscoveredAddress
{
    public function __construct(
        public string $ip,
        public int $vmid,
        public string $node,
        public string $guestName,
        public ?string $macAddress,
        public ?string $bridge,
        public ?int $vlan,
    ) {
    }
}
