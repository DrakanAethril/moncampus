<?php

declare(strict_types=1);

namespace App\Service\Proxmox;

use App\Entity\IpRange;

/**
 * What the creation wizard collected, as one value object.
 *
 * A parameter object rather than fourteen arguments, and readonly so nothing rewrites a decision
 * between the summary screen and the call. `sourceVmid` null means "from an ISO" - the path where
 * MonCampus **allocates but cannot configure**, which is the branch the wizard's second step
 * changes shape for.
 */
final readonly class GuestCreationRequest
{
    public function __construct(
        public string $hostname,
        public int $vmid,
        public string $node,
        public int $cores,
        public int $memoryMib,
        public int $diskGib,
        public string $storage,
        public IpRange $range,
        public string $ip,
        public ?int $sourceVmid,
        public bool $linkedClone,
        public ?string $isoVolumeId,
        public bool $startAfterCreation,
        public ?string $postInstallScript = null,
    ) {
    }

    /** Whether the machine can be given its identity before it first boots. */
    public function isConfigurable(): bool
    {
        return null !== $this->sourceVmid;
    }
}
