<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LdapManageAccount;
use App\Enum\LdapAccountAction;
use App\Repository\UserRepository;
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
    /** Set when a rename could not be applied because a local row already carries the new login. */
    public const string NOTE_LOGIN_TAKEN_LOCALLY = 'ldapAccountApplyLoginTakenNote';

    public function __construct(
        private readonly LdapAccountVerifier $verifier,
        private readonly UserRepository $users,
        private readonly StudentMailProvisioner $mailProvisioner,
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
        if (null !== $request->getVerificationDate() && null === $request->getAppliedAt() && $this->apply($request)) {
            $request->setAppliedAt(new \DateTimeImmutable());
        }

        $this->entityManager->flush();
    }

    /**
     * A deactivation and a reactivation have nothing to do here, and that is the asymmetry rather
     * than an omission: both took effect at the click, on App\Entity\User::$inactiveDate. Closing
     * the platform asks the directory's permission for nothing, and opening it again is the same
     * gesture backwards; all that is left is to record that the loop is closed.
     *
     * A rename is the opposite, and is the reason this method exists at all - the one action whose
     * consequence on this side waits on the directory. It moves three things and leaves a fourth
     * alone:
     *
     *  - **`User::$username`**, which is the truth: the user provider looks accounts up by it and
     *    LdapCredentialsVerifier searches the directory by it. Rewriting it any earlier would make
     *    the account unreachable on both sides at once.
     *  - **The session in progress**, which falls of its own accord at the next request, the
     *    provider no longer finding that name. Wanted, and announced in the modal.
     *  - **The School mail address derived from the login**, added if its local part is free.
     *  - The **old address, which stays**: reception is a catch-all, mail has already gone out to
     *    it, and removing it would lose letters without freeing anything.
     *
     * @return bool false when the consequence could not be drawn, applied_at then staying NULL so
     *              the screen goes on saying the operation is not settled
     */
    private function apply(LdapManageAccount $request): bool
    {
        if (LdapAccountAction::LoginChange !== $request->getActionType()) {
            return true;
        }

        $newLogin = $request->getNewLogin();

        if (null === $newLogin) {
            return true;
        }

        $user = $request->getUser();

        if ($newLogin === $user->getUsername()) {
            // Already applied by the other caller between this row being read and this line.
            return true;
        }

        $holder = $this->users->findOneBy(['username' => $newLogin]);

        if (null !== $holder && $holder !== $user) {
            // The login was free when the request was posted (LdapAccountRequestService checks both
            // sources) and somebody has taken it since. Refusing here rather than letting the unique
            // constraint blow up in a cron: the directory has already renamed the entry, so this is
            // a state a human has to look at, not one to crash on.
            $request->setVerificationNote(self::NOTE_LOGIN_TAKEN_LOCALLY);

            return false;
        }

        $user->setUsername($newLogin);
        $this->mailProvisioner->addLoginAlias($user, $newLogin);

        return true;
    }
}
