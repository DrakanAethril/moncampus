<?php

declare(strict_types=1);

namespace App\Service\Guest;

use App\Entity\GuestAccount;

/**
 * One machine as its own user sees it: what it is called, where it answers, whether it is running,
 * and the login they have on it.
 *
 * A read model rather than the entity, because two of the four values are not in the database. The
 * status is only ever true at the moment it is read, and the address belongs to the batch's item
 * rather than to the account - and a template that had to go and fetch either would put a Proxmox
 * call inside a loop.
 */
final readonly class UserMachine
{
    public function __construct(
        public GuestAccount $account,
        public string $name,
        public ?string $ip,
        /** `running`, `stopped`, or null when the hypervisor could not be asked. */
        public ?string $status,
        public ?string $batchLabel,
    ) {
    }

    public function isRunning(): bool
    {
        return 'running' === $this->status;
    }

    /** Whether anything can be done to it - a machine whose host is unreachable is not one to act on. */
    public function isKnown(): bool
    {
        return null !== $this->status;
    }
}
