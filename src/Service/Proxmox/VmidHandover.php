<?php

declare(strict_types=1);

namespace App\Service\Proxmox;

use App\Entity\ProxmoxHost;
use App\Enum\IpAllocationStatus;
use App\Repository\GuestAccountRepository;
use App\Repository\IpAllocationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Hands a VMID over from the machine that held it to the one that just took it.
 *
 * **A VMID is a slot, not a machine.** Proxmox hands the number back the moment a machine is
 * deleted, and MonCampus stores no machine - so everything it records about one is keyed on
 * (host, VMID) and goes on pointing at that number long after the disk is gone. Nothing here
 * deletes machines, and the deletion happens in Proxmox with no notice, so those rows are never
 * cleaned up by the gesture that made them obsolete.
 *
 * That is fine until the number is handed out again, at which point the platform holds two sets of
 * records for one slot and reads whichever it finds first. In production that meant a class's new
 * batch deployed onto the numbers an old one had freed: the accounts screen showed the *previous*
 * class's students as missing on the machine and offered to create them, the address registry
 * answered with the dead machine's address, and every account gesture then ran against a machine
 * that was not the one on screen - reporting accounts as absent that were in fact there.
 *
 * This is the gesture that closes it, and it runs at the one instant where the answer is certain:
 * **Proxmox has just accepted a creation at that VMID**, which it only does for a number nobody
 * holds. So whatever is still recorded under it belongs to a machine that no longer exists.
 *
 * Two things are swept, and nothing else:
 *
 *  - **the address rows go back on offer.** The machine that carried them is gone; leaving them
 *    live holds addresses no machine uses and, worse, leaves the registry able to answer the new
 *    machine's number with the old machine's address.
 *  - **the account rows are forgotten.** An account row says « this person has a login inside that
 *    machine »; the machine is gone, and so are the login, the home directory and the files. This
 *    is the same removal App\Service\Guest\StaleGuestAccountPruner performs, reached from the other
 *    direction: the pruner notices a machine that disappeared, this notices one that was replaced.
 *
 * **It destroys nothing on any hypervisor** and cannot: it forgets rows about a machine Proxmox has
 * already dropped - the proof being that it just accepted a new one at the same number.
 */
class VmidHandover
{
    public function __construct(
        private readonly IpAllocationRepository $allocations,
        private readonly GuestAccountRepository $accounts,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Forgets the previous occupant of one VMID.
     *
     * **Call it before the new machine's own address is assigned that VMID**, never after: what
     * separates the outgoing rows from the incoming one is precisely that the incoming one does not
     * carry the number yet. An exclusion list would say the same thing less reliably.
     *
     * @return array{addresses: int, accounts: int} what was let go, for the caller's own log
     */
    public function reclaim(ProxmoxHost $host, int $vmid): array
    {
        $addresses = $this->allocations->findLiveForVmid($host, $vmid);

        foreach ($addresses as $allocation) {
            $allocation->setStatus(IpAllocationStatus::Released);
        }

        // Node-blind: the machine that was destroyed may have been migrated since its accounts were
        // declared, and rows left on the node it was born on would survive a node-scoped sweep and
        // keep answering for the number.
        $accounts = $this->accounts->findForVmid($host, $vmid);

        foreach ($accounts as $account) {
            $this->entityManager->remove($account);
        }

        if ([] !== $addresses || [] !== $accounts) {
            $this->entityManager->flush();
        }

        return ['addresses' => \count($addresses), 'accounts' => \count($accounts)];
    }
}
