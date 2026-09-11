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
use App\Service\Proxmox\ProxmoxOperationTracker;
use App\Service\Proxmox\ProxmoxUnavailableException;

/**
 * The machines one person holds an account on, ready to be shown.
 *
 * **One inventory call per host, never one per machine.** A class's worth of accounts can sit on a
 * single hypervisor, and asking it about each of them in turn is how a page becomes as slow as the
 * number of machines on it. The guests are read once, by App\Service\Guest\GuestMachineLocator,
 * and matched by (host, VMID) - a VMID is unique across a cluster, and a machine migrated to
 * another node is still the same machine.
 *
 * A host that cannot be reached is not an error here: its machines are listed with an unknown
 * status, which is what an unreachable hypervisor honestly means. Refusing to draw the page would
 * hide the machines of every *other* host along with it - and the person reading this screen has no
 * way to act on a hypervisor being down anyway.
 *
 * **A machine the hypervisor no longer holds is not listed at all**, and that is the same rule
 * /infrastructure applies: nothing about a machine is stored, so one destroyed in Proxmox simply
 * stops being listed. An account row outlives its machine - the batch that created it may be gone
 * too - and until this filter existed it kept a card on a student's screen for a machine that no
 * longer existed anywhere, with buttons that could only fail. Note the difference this leans on,
 * which App\Service\Guest\GuestMachineIndex draws: gone means *a host that answered does not hold
 * it*, never *a host that did not answer*.
 */
class UserMachineFinder
{
    public function __construct(
        private readonly GuestAccountRepository $accounts,
        private readonly GuestMachineLocator $locator,
        private readonly ProxmoxOperationRepository $operations,
        private readonly IpAllocationRepository $allocations,
        private readonly ProxmoxOperationTracker $tracker,
        private readonly ProxmoxClientFactory $clientFactory,
    ) {
    }

    /** @return list<UserMachine> */
    public function forUser(User $user): array
    {
        $accounts = $this->accounts->findForUser($user);

        if ([] === $accounts) {
            return [];
        }

        $index = $this->locator->index($accounts);
        // Judged before anything else is read: the machines that are gone must not weigh on the
        // queries that follow, and an empty list after the filter is an empty screen, not an error.
        $accounts = array_values(array_filter($accounts, static fn (GuestAccount $account): bool => !$index->isGone($account)));

        if ([] === $accounts) {
            return [];
        }

        $hosts = $this->hostsOf($accounts);
        $logins = $this->loginsByHost($hosts, $accounts);
        $pending = $this->pendingByHost($hosts, $accounts);
        $addresses = $this->addressesByHost($hosts, $accounts);
        $machines = [];

        foreach ($accounts as $account) {
            $host = $account->getHost();
            $hostId = $host?->getId() ?? 0;
            $guest = $index->machineOf($account);
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
                $item?->getIpAllocation()?->getIp() ?? $addresses[$hostId][$account->getVmid()] ?? null,
                $guest?->status,
                $batch?->getLabel(),
                $logins[\sprintf('%d/%s/%d', $hostId, $account->getNode(), $account->getVmid())] ?? [$account->getLogin()],
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
     * The machines these accounts sit on, as VMIDs per host - the set two lookups now narrow
     * themselves with, one query and one hypervisor call at a time rather than one per machine.
     *
     * @param list<GuestAccount> $accounts
     *
     * @return array<int, array<int, int>>
     */
    private function vmidsByHost(array $accounts): array
    {
        $vmids = [];

        foreach ($accounts as $account) {
            $host = $account->getHost();

            if (null !== $host && null !== $host->getId()) {
                $vmids[$host->getId()][$account->getVmid()] = $account->getVmid();
            }
        }

        return $vmids;
    }

    /**
     * Where each machine answers, per host.
     *
     * Per host and not in one sweep by VMID, for the reason this whole class keys on (host, VMID):
     * a VMID is unique inside one cluster and nowhere else, and the registry keeps the rows of the
     * machines that held a number before the current one. Asked by the number alone it answered a
     * student's card with a dead machine's address.
     *
     * @param array<int, ProxmoxHost> $hosts
     * @param list<GuestAccount>      $accounts
     *
     * @return array<int, array<int, string>> host id => vmid => address
     */
    private function addressesByHost(array $hosts, array $accounts): array
    {
        $vmids = $this->vmidsByHost($accounts);
        $addresses = [];

        foreach ($hosts as $hostId => $host) {
            $addresses[$hostId] = $this->allocations->findAddressesForVmids($host, array_values($vmids[$hostId] ?? []));
        }

        return $addresses;
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
        $vmids = $this->vmidsByHost($accounts);
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
     * **Each one is asked about before it is believed**, exactly as the operations journal does it:
     * an open row says a request went out, never that it is still going. Nothing else on this
     * screen's side of the application ever closed such a row - the journal is under
     * /infrastructure, which is ROLE_ADMIN - so a student's own start stayed « Démarrage… » until
     * an administrator happened to open a screen they cannot reach, and the card's auto-refresh
     * reloaded the page for ever on a machine that had been up for an hour.
     *
     * A host that will not answer is not an error here either: the row stays open, and
     * App\Service\Guest\UserMachine holds the second half of the rule - a machine that already
     * answers `running` has started, whatever its task still says.
     *
     * @param array<int, ProxmoxHost> $hosts
     * @param list<GuestAccount>      $accounts
     *
     * @return array<int, array<int, ProxmoxAction>>
     */
    private function pendingByHost(array $hosts, array $accounts): array
    {
        $wanted = $this->vmidsByHost($accounts);
        $pending = [];

        foreach ($hosts as $hostId => $host) {
            foreach ($this->operations->findUnsettledByVmid($host) as $vmid => $operation) {
                $action = $operation->getAction();

                // Only the four that move a machine between on and off, and only on the machines
                // this screen is about. A clone, a provisioning run, or somebody else's class being
                // started says nothing about this card's state - and asking the hypervisor about it
                // would be one HTTP call spent on a row nobody here will read.
                if (!$action->isPowerAction() || !isset($wanted[$hostId][$vmid])) {
                    continue;
                }

                try {
                    // By action rather than by convenience: Proxmox reads back your own tasks for
                    // free and charges Sys.Audit for anybody else's.
                    $this->tracker->resolve($operation, $this->clientFactory->forAction($host, $action));
                } catch (ProxmoxUnavailableException) {
                    // Left open on purpose: the tracker alone decides when an unreachable host
                    // turns into `unknown`, and that is a matter of elapsed time, not of one
                    // failed poll.
                }

                if (!$operation->getStatus()->isSettled()) {
                    $pending[$hostId][$vmid] = $action;
                }
            }
        }

        return $pending;
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
