<?php

declare(strict_types=1);

namespace App\Service\Network;

use App\Entity\IpAllocation;
use App\Enum\IpAllocationOrigin;
use App\Enum\IpAllocationStatus;

/**
 * What the registry believes about one address, flattened to plain values so
 * App\Service\Network\AddressReconciler can compare it with what the scan found without ever
 * touching an entity.
 *
 * `origin` and `status` both travel because both change the verdict: an *external* entry is a
 * printer and must never be called orphaned, and a *reserved* one is a creation in flight and must
 * never be called orphaned either - a wizard that is still open has no machine to find yet.
 */
final readonly class RegisteredAddress
{
    public function __construct(
        public string $ip,
        public ?int $vmid,
        public ?string $hostname,
        public IpAllocationStatus $status,
        public IpAllocationOrigin $origin,
    ) {
    }

    public static function fromAllocation(IpAllocation $allocation): self
    {
        return new self(
            $allocation->getIp(),
            $allocation->getVmid(),
            $allocation->getHostname(),
            $allocation->getStatus(),
            $allocation->getOrigin(),
        );
    }

    /**
     * Whether the scan is entitled to expect a machine at this address.
     *
     * Two entries are exempt, for opposite reasons: an external one never had a machine, and a
     * reserved one does not have one *yet*.
     */
    public function expectsAGuest(): bool
    {
        return IpAllocationOrigin::External !== $this->origin
            && IpAllocationStatus::Reserved !== $this->status;
    }
}
