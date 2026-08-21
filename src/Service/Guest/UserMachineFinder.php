<?php

declare(strict_types=1);

namespace App\Service\Guest;

use App\Entity\GuestAccount;
use App\Entity\ProxmoxHost;
use App\Entity\User;
use App\Entity\VmBatchItem;
use App\Enum\ProxmoxAction;
use App\Repository\GuestAccountRepository;
use App\Repository\IpAllocationRepository;
use App\Repository\ProxmoxOperationRepository;
use App\Service\Proxmox\ProxmoxClientFactory;
use App\Service\Proxmox\ProxmoxGuest;
use App\Service\Proxmox\ProxmoxInventory;
use App\Service\Proxmox\ProxmoxUnavailableException;

/**
 * The machines one person holds an account on, ready to be shown.
 *
 * **One inventory call per host, never one per machine.** A class's worth of accounts can sit on a
 * single hypervisor, and asking it about each of them in turn is how a page becomes as slow as the
 * number of machines on it. The guests are read once and matched by (node, VMID).
 *
 * A host that cannot be reached is not an error here: its machines are listed with an unknown
 * status, which is what an unreachable hypervisor honestly means. Refusing to draw the page would
 * hide the machines of every *other* host along with it - and the person reading this screen has no
 * way to act on a hypervisor being down anyway.
 */
class UserMachineFinder
{
    public function __construct(
        private readonly GuestAccountRepository $accounts,
        private readonly ProxmoxClientFactory $clientFactory,
        private readonly ProxmoxInventory $inventory,
        private readonly ProxmoxOperationRepository $operations,
        private readonly IpAllocationRepository $allocations,
    ) {
    }

    /** @return list<UserMachine> */
    public function forUser(User $user): array
    {
        $accounts = $this->accounts->findForUser($user);

        if ([] === $accounts) {
            return [];
        }

        $hosts = $this->hostsOf($accounts);
        $guests = $this->guestsByHost($hosts);
        $logins = $this->loginsByHost($hosts, $accounts);
        $pending = $this->pendingByHost($hosts);
        $addresses = $this->allocations->findAddressesForVmids(array_values(array_unique(
            array_map(static fn (GuestAccount $account): int => $account->getVmid(), $accounts),
        )));
        $machines = [];

        foreach ($accounts as $account) {
            $host = $account->getHost();
            $hostId = $host?->getId() ?? 0;
            $key = \sprintf('%d/%s/%d', $hostId, $account->getNode(), $account->getVmid());
            $guest = $guests[$key] ?? null;
            $item = $this->itemFor($account);
            $batch = $account->getBatch();

            $machines[] = new UserMachine(
                $account,
                // The batch's own name for it first: it is the name the machine answers to on the
                // network, and the one written on the board. Proxmox's is the fallback because a
                // machine declared outside a batch has no other.
                // `->` and not `?->`: null-coalescing already swallows a read on null, and
                // PHPStan calls the nullsafe redundant there.
                $item?->getGuestName() ?? $guest->name ?? \sprintf('VM %d', $account->getVmid()),
                // The batch's own allocation first, the registry as the fallback - the same order
                // MyMachineController uses to decide where to open an SSH session, because a card
                // showing one address while the password went to another is a bug nobody can see.
                $item?->getIpAllocation()?->getIp() ?? $addresses[$account->getVmid()] ?? null,
                $guest?->status,
                $batch?->getLabel(),
                $logins[$key] ?? [$account->getLogin()],
                // The hypervisor first, because it knows what the machine actually got; the batch
                // is what was *asked for*, and it is all there is when the host cannot be reached.
                $guest->maxMemoryBytes ?? (null !== $batch ? $batch->getMemoryMib() * 1024 * 1024 : null),
                $guest->maxDiskBytes ?? (null !== $batch ? $batch->getDiskGib() * 1024 * 1024 * 1024 : null),
                $pending[$hostId][$account->getVmid()] ?? null,
            );
        }

        return $machines;
    }

    /**
     * The hosts these accounts sit on, keyed by id.
     *
     * Pulled out because three separate lookups now need the same set, and each of them costs one
     * query - or one call to a hypervisor - per host rather than per machine.
     *
     * @param list<GuestAccount> $accounts
     *
     * @return array<int, ProxmoxHost>
     */
    private function hostsOf(array $accounts): array
    {
        $hosts = [];

        foreach ($accounts as $account) {
            $host = $account->getHost();

            if (null !== $host && null !== $host->getId()) {
                $hosts[$host->getId()] = $host;
            }
        }

        return $hosts;
    }

    /**
     * Every login declared on the machines concerned, keyed the same way as the guests.
     *
     * The whole point of « Comptes » on a card is that it lists the machine's accounts and not the
     * reader's, so this asks per host with the VMIDs in one `IN`.
     *
     * @param array<int, ProxmoxHost> $hosts
     * @param list<GuestAccount>      $accounts
     *
     * @return array<string, list<string>>
     */
    private function loginsByHost(array $hosts, array $accounts): array
    {
        $vmids = [];

        foreach ($accounts as $account) {
            $host = $account->getHost();

            if (null !== $host && null !== $host->getId()) {
                $vmids[$host->getId()][$account->getVmid()] = $account->getVmid();
            }
        }

        $logins = [];

        foreach ($hosts as $hostId => $host) {
            foreach ($this->accounts->findLoginsOnMachines($host, array_values($vmids[$hostId] ?? [])) as $machine => $found) {
                $logins[\sprintf('%d/%s', $hostId, $machine)] = $found;
            }
        }

        return $logins;
    }

    /**
     * The power action still under way on each machine, per host.
     *
     * @param array<int, ProxmoxHost> $hosts
     *
     * @return array<int, array<int, ProxmoxAction>>
     */
    private function pendingByHost(array $hosts): array
    {
        $pending = [];

        foreach ($hosts as $hostId => $host) {
            foreach ($this->operations->findUnsettledByVmid($host) as $vmid => $operation) {
                $action = $operation->getAction();

                // Only the four that move a machine between on and off. A clone or a provisioning
                // run is somebody else's business and says nothing about this card's state.
                if ($action->isPowerAction()) {
                    $pending[$hostId][$vmid] = $action;
                }
            }
        }

        return $pending;
    }

    /**
     * Every guest of every host these accounts sit on, keyed by host/node/VMID.
     *
     * @param array<int, ProxmoxHost> $hosts
     *
     * @return array<string, ProxmoxGuest>
     */
    private function guestsByHost(array $hosts): array
    {
        $guests = [];

        foreach ($hosts as $host) {
            foreach ($this->guestsOf($host) as $guest) {
                $guests[\sprintf('%d/%s/%d', $host->getId() ?? 0, $guest->node, $guest->vmid)] = $guest;
            }
        }

        return $guests;
    }

    /** @return list<ProxmoxGuest> */
    private function guestsOf(ProxmoxHost $host): array
    {
        try {
            return $this->inventory->guests($this->clientFactory->operate($host));
        } catch (ProxmoxUnavailableException) {
            // Unknown, not broken - see the class docblock.
            return [];
        }
    }

    /**
     * The batch's row for this machine, which is where its name and its address live.
     *
     * Read off the batch already loaded rather than queried: a person holds a handful of accounts,
     * and their batches carry a handful of items each.
     */
    private function itemFor(GuestAccount $account): ?VmBatchItem
    {
        $batch = $account->getBatch();

        if (null === $batch) {
            return null;
        }

        foreach ($batch->getItems() as $item) {
            if ($item->getVmid() === $account->getVmid()) {
                return $item;
            }
        }

        return null;
    }
}
