<?php

declare(strict_types=1);

namespace App\Service\Guest;

use App\Entity\GuestAccount;
use App\Service\Proxmox\ProxmoxGuest;

/**
 * What the hypervisors answered about the machines a set of accounts points at.
 *
 * The whole point of this object is the difference between the two ways a machine can fail to be
 * found, which the raw guest list cannot express: **« the host said no » is not « the host said
 * nothing »**. A machine absent from a host that answered has been destroyed in Proxmox and the
 * account inside it no longer describes anything; a machine absent because the host could not be
 * reached is simply unknown, and forgetting that difference would empty a student's screen - or,
 * worse, delete their accounts - the day a hypervisor is rebooted.
 *
 * Machines are indexed by **(host, VMID) and not by node**: a VMID is unique across a cluster, and
 * a machine migrated to another node is still very much there. Keying on the node the account was
 * born on would call every migration a destruction.
 *
 * Templates are not machines here, exactly as on /infrastructure: they are what the Images screen
 * is about, and an account declared on one describes an image nobody works in.
 */
final readonly class GuestMachineIndex
{
    /**
     * @param array<string, ProxmoxGuest> $machines   keyed `hostId/vmid`, templates already dropped
     * @param array<int, true>            $unanswered ids of the hosts that could not be read
     */
    public function __construct(private array $machines = [], private array $unanswered = [])
    {
    }

    /** The machine this account sits in, as the hypervisor reports it right now. */
    public function machineOf(GuestAccount $account): ?ProxmoxGuest
    {
        return $this->machines[$this->keyOf($account)] ?? null;
    }

    /**
     * True only when a host that answered does not hold this machine any more.
     *
     * Deliberately false for a host that did not answer: not knowing is not the same as knowing it
     * is gone, and every caller of this class either hides or deletes what it returns true for.
     */
    public function isGone(GuestAccount $account): bool
    {
        $hostId = $account->getHost()?->getId();

        if (null === $hostId || isset($this->unanswered[$hostId])) {
            return false;
        }

        return !isset($this->machines[$this->keyOf($account)]);
    }

    public function isUnanswered(GuestAccount $account): bool
    {
        $hostId = $account->getHost()?->getId();

        return null === $hostId || isset($this->unanswered[$hostId]);
    }

    private function keyOf(GuestAccount $account): string
    {
        return \sprintf('%d/%d', $account->getHost()?->getId() ?? 0, $account->getVmid());
    }
}
