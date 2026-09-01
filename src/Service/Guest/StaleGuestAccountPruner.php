<?php

declare(strict_types=1);

namespace App\Service\Guest;

use App\Entity\GuestAccount;
use App\Entity\VmBatch;
use App\Repository\GuestAccountRepository;
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
 */
class StaleGuestAccountPruner
{
    public function __construct(
        private readonly GuestMachineLocator $locator,
        private readonly GuestAccountRepository $accounts,
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
        $stale = [];
        $undecided = [];
        $kept = 0;

        foreach ($accounts as $account) {
            if ($index->isGone($account)) {
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
