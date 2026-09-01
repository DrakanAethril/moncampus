<?php

declare(strict_types=1);

namespace App\Service\Guest;

use App\Entity\GuestAccount;
use App\Entity\ProxmoxHost;
use App\Service\Proxmox\ProxmoxClientFactory;
use App\Service\Proxmox\ProxmoxInventory;
use App\Service\Proxmox\ProxmoxUnavailableException;

/**
 * Asks each hypervisor once which machines it still holds, for a set of accounts.
 *
 * **One inventory call per host, never one per machine** - a class's worth of accounts sits on a
 * single hypervisor, and `GET /cluster/resources` already covers every node of it.
 *
 * A host that refuses or times out is recorded as unanswered rather than as empty; see
 * App\Service\Guest\GuestMachineIndex for why that distinction is the whole point.
 */
class GuestMachineLocator
{
    public function __construct(
        private readonly ProxmoxClientFactory $clientFactory,
        private readonly ProxmoxInventory $inventory,
    ) {
    }

    /** @param list<GuestAccount> $accounts */
    public function index(array $accounts): GuestMachineIndex
    {
        $machines = [];
        $unanswered = [];

        foreach ($this->hostsOf($accounts) as $hostId => $host) {
            try {
                $guests = $this->inventory->machines($this->inventory->guests($this->clientFactory->operate($host)));
            } catch (ProxmoxUnavailableException) {
                $unanswered[$hostId] = true;

                continue;
            }

            foreach ($guests as $guest) {
                $machines[\sprintf('%d/%d', $hostId, $guest->vmid)] = $guest;
            }
        }

        return new GuestMachineIndex($machines, $unanswered);
    }

    /**
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
}
