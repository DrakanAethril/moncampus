<?php

declare(strict_types=1);

namespace App\Service\Proxmox;

use App\Entity\ProxmoxHost;

/**
 * A host's perimeter, as plain values. Nothing but numbers, strings and booleans, so that
 * App\Service\Proxmox\ProxmoxScopeGuard can be reasoned about - and tested - without a database
 * and without an entity.
 *
 * Every field is nullable in the "not declared" sense rather than the "empty" sense: a host that
 * names no pool is not a host whose pool is nothing, it is a host where the pool is not part of
 * the perimeter. The guard treats the two very differently.
 */
final readonly class ProxmoxScope
{
    public function __construct(
        public ?string $managedPool,
        public ?int $vmidMin,
        public ?int $vmidMax,
        public bool $allowStart,
        public bool $allowStop,
        public bool $allowCreate,
        public ?int $maxGuests,
        public ?int $maxCores,
        public ?int $maxMemoryMib,
        public ?int $maxDiskGib,
    ) {
    }

    public static function fromHost(ProxmoxHost $host): self
    {
        $pool = $host->getManagedPool();

        return new self(
            null !== $pool && '' !== trim($pool) ? trim($pool) : null,
            $host->getVmidMin(),
            $host->getVmidMax(),
            $host->isAllowStart(),
            $host->isAllowStop(),
            // The host's own switch is not enough: without the second credential set there is no
            // account carrying VM.Allocate, so creation is unavailable whatever the box says.
            $host->canCreateGuests(),
            $host->getMaxGuests(),
            $host->getMaxCores(),
            $host->getMaxMemoryMib(),
            $host->getMaxDiskGib(),
        );
    }
}
