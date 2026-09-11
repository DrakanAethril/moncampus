<?php

declare(strict_types=1);

namespace App\Service\Guest;

use App\Entity\GuestAccount;
use App\Entity\VmBatch;
use App\Repository\GuestAccountRepository;
use App\Repository\VmBatchItemRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Removes the account rows that describe machines nobody can reach any more, because they no longer
 * exist.
 *
 * An account row is not a machine: it says « this person has a login inside that machine ». When the
 * machine is destroyed in Proxmox the row stops describing anything, and it is the *only* thing left
 * pointing the person at it - the batch may well have been deleted too. Left in place it keeps a
 * card on « Mes machines virtuelles », keeps the navigation entry lit (which is answered by a count,
 * not by the hypervisor), and offers buttons whose every path ends in « la machine est introuvable ».
 *
 * **Deleting a row here destroys nothing.** MonCampus never destroys a machine, and this runs
 * strictly after somebody else already did: it forgets an account inside a machine that is gone.
 * The account itself, the home directory, the files - they went with the disk.
 *
 * The one rule this must never break: an unreachable host decides nothing. See
 * App\Service\Guest\GuestMachineIndex - not knowing is not the same as knowing it is gone, and this
 * class is the one place where getting that difference wrong would be irreversible.
 *
 * **A machine can also disappear without its number disappearing with it**, and that is the second
 * rule here. Proxmox hands a VMID back the moment a machine is deleted, so a later deployment takes
 * the number and the hypervisor answers « yes, 9002 is here » about a machine that has nothing to do
 * with the rows still filed under it. The account rows of the previous occupant then read as current:
 * the accounts screen offers to create a former class's students on the machine, and does.
 *
 * App\Service\Proxmox\VmidHandover closes that at the source, when the new machine is created. This
 * is the other half, for the rows a deployment made obsolete before the handover existed: an account
 * whose batch is not the batch that built the machine now holding its (host, VMID) describes a
 * machine that is gone, and no hypervisor needs to be asked to know it. An account filed under no
 * batch at all, or under a number no deployment claims, is left exactly where it is - this decides
 * only what it can decide.
 */
class StaleGuestAccountPruner
{
    public function __construct(
        private readonly GuestMachineLocator $locator,
        private readonly GuestAccountRepository $accounts,
        private readonly VmBatchItemRepository $items,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Looks, and changes nothing - what `--dry-run` prints and what the tests assert on.
     *
     * @param list<GuestAccount> $accounts
     */
    public function inspect(array $accounts): StaleGuestAccountReport
    {
        if ([] === $accounts) {
            return new StaleGuestAccountReport();
        }

        $index = $this->locator->index($accounts);
        $occupants = $this->items->findDeployedBatchIdsByHostAndVmid();
        $stale = [];
        $undecided = [];
        $kept = 0;

        foreach ($accounts as $account) {
            // Asked before the hypervisor's answer, and independent of it: a number taken over by a
            // later deployment is decided from the platform's own records, so an unreachable host
            // does not turn a certainty into a wait.
            if ($this->isSuperseded($account, $occupants)) {
                $stale[] = $account;
            } elseif ($index->isGone($account)) {
                $stale[] = $account;
            } elseif ($index->isUnanswered($account)) {
                $undecided[] = $account;
            } else {
                ++$kept;
            }
        }

        return new StaleGuestAccountReport($stale, $undecided, $kept);
    }

    /**
     * Whether the machine this account names is now held by a different deployment.
     *
     * Both sides must be known for the answer to be yes: an account filed under no batch says
     * nothing about which deployment built the machine, and a (host, VMID) no deployed item claims
     * says nothing about who holds it. Either way the row is left alone - this is a sweep, and a
     * sweep that guesses is a sweep nobody dares run.
     *
     * @param array<int, array<int, int>> $occupants host id => vmid => batch id
     */
    private function isSuperseded(GuestAccount $account, array $occupants): bool
    {
        $batchId = $account->getBatch()?->getId();
        $hostId = $account->getHost()?->getId();

        if (null === $batchId || null === $hostId) {
            return false;
        }

        $current = $occupants[$hostId][$account->getVmid()] ?? null;

        return null !== $current && $current !== $batchId;
    }

    /**
     * Looks, then removes what it found - one flush for the pass.
     *
     * @param list<GuestAccount> $accounts
     */
    public function prune(array $accounts): StaleGuestAccountReport
    {
        $report = $this->inspect($accounts);

        foreach ($report->stale as $account) {
            $this->entityManager->remove($account);
        }

        if ([] !== $report->stale) {
            $this->entityManager->flush();
        }

        return $report;
    }

    /**
     * The accounts of one batch, judged the same way.
     *
     * Called when a batch is deleted: the plan disappears, the machines it built go on running, and
     * the accounts inside the ones that no longer exist go with them.
     */
    public function pruneBatch(VmBatch $batch): StaleGuestAccountReport
    {
        return $this->prune($this->accounts->findForBatch($batch));
    }

    /** Every account MonCampus knows about, for the scheduled - or one-off - sweep. */
    public function pruneAll(): StaleGuestAccountReport
    {
        return $this->prune($this->accounts->findAllOrdered());
    }

    /** The same sweep, looking only. */
    public function inspectAll(): StaleGuestAccountReport
    {
        return $this->inspect($this->accounts->findAllOrdered());
    }
}
