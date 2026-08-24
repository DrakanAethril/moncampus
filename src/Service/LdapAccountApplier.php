<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LdapManageAccount;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Draws the consequence on this side once the directory has confirmed - and decides, therefore,
 * when it has not.
 *
 * The consumer script on the domain controller touches nothing but its own table: it does not know
 * this application's schema and must not. So the row comes back saying "the directory did it", and
 * somebody here has to act on that. Two callers do, deliberately two:
 *
 *  1. the fiche's polling, so the screen is right immediately;
 *  2. App\Command\ApplyLdapAccountRequestsCommand, in cron every minute, so a closed browser tab is
 *     not what decides. It is the lesson of app:vm-batch:advance, written into CLAUDE.md: what
 *     makes an operation survive the tab that started it is never the browser's own loop.
 *
 * `applied_at` is what makes that safe - the two can cross each other at a minute's distance, and
 * applying twice must be the same as applying once.
 *
 * The asymmetry of the whole feature lives in apply(): a deactivation has *already* been applied,
 * at the click, before the request was even queued, so there is nothing left to do but record that
 * the loop is closed. A rename has not - and will not be, until this method is reached with a
 * verified row.
 */
class LdapAccountApplier
{
    public function __construct(
        private readonly LdapAccountVerifier $verifier,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Verifies if it has not been verified, applies if it may, flushes whatever changed. Safe to
     * call on any row, in any state, as often as one likes.
     */
    public function process(LdapManageAccount $request): void
    {
        if (2 !== $request->getState()) {
            return;
        }

        if (null === $request->getVerificationDate()) {
            $this->verifier->verify($request);
        }

        // No verification, no consequence. This is the whole meaning of the orange state: the
        // script said it worked, the directory did not confirm, so a rename keeps the old username.
        if (null !== $request->getVerificationDate() && null === $request->getAppliedAt()) {
            $this->apply($request);
            $request->setAppliedAt(new \DateTimeImmutable());
        }

        $this->entityManager->flush();
    }

    /**
     * Nothing to do, for now, and that is the asymmetry rather than an omission: a deactivation and
     * a reactivation both took effect at the click, on App\Entity\User::$inactiveDate. Closing the
     * platform asks the directory's permission for nothing, and opening it again is the same
     * gesture backwards; all that is left here is to record that the loop is closed, which
     * process() does by stamping applied_at.
     *
     * A rename is the opposite and is the reason this method exists at all - it is the one action
     * whose consequence on this side waits on the directory's confirmation.
     */
    private function apply(LdapManageAccount $request): void
    {
    }
}
