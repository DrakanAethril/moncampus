<?php

declare(strict_types=1);

namespace App\Service\Guest;

use App\Entity\GuestAccount;
use App\Entity\ProxmoxHost;
use App\Entity\User;
use App\Entity\VmBatchItem;
use App\Repository\GuestAccountRepository;
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
    ) {
    }

    /** @return list<UserMachine> */
    public function forUser(User $user): array
    {
        $accounts = $this->accounts->findForUser($user);

        if ([] === $accounts) {
            return [];
        }

        $guests = $this->guestsByHost($accounts);
        $machines = [];

        foreach ($accounts as $account) {
            $host = $account->getHost();
            $key = \sprintf('%d/%s/%d', $host?->getId() ?? 0, $account->getNode(), $account->getVmid());
            $guest = $guests[$key] ?? null;
            $item = $this->itemFor($account);

            $machines[] = new UserMachine(
                $account,
                // The batch's own name for it first: it is the name the machine answers to on the
                // network, and the one written on the board. Proxmox's is the fallback because a
                // machine declared outside a batch has no other.
                // `->` and not `?->`: null-coalescing already swallows a read on null, and
                // PHPStan calls the nullsafe redundant there.
                $item?->getGuestName() ?? $guest->name ?? \sprintf('VM %d', $account->getVmid()),
                $item?->getIpAllocation()?->getIp(),
                $guest?->status,
                $account->getBatch()?->getLabel(),
            );
        }

        return $machines;
    }

    /**
     * Every guest of every host these accounts sit on, keyed by host/node/VMID.
     *
     * @param list<GuestAccount> $accounts
     *
     * @return array<string, ProxmoxGuest>
     */
    private function guestsByHost(array $accounts): array
    {
        $hosts = [];

        foreach ($accounts as $account) {
            $host = $account->getHost();

            if (null !== $host && null !== $host->getId()) {
                $hosts[$host->getId()] = $host;
            }
        }

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
