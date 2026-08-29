<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Entity\UserLogin;
use App\Repository\UserLoginRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The only writer of `user_login`, and the one place the two halves of the rule are stated.
 *
 * **A login belongs to whoever was first given it, for ever.** Renaming an account does not hand
 * the old login back to the pool: mail has already been sent from it, a home directory on the file
 * server carries it, and a second person answering to it would inherit the first one's past.
 *
 * **The same person may take their own login back.** That is not an exception carved into the rule
 * but a consequence of its shape: coming back to a login one already has a row for clears
 * `releasedAt` instead of inserting, so the unique index is never in the way, and no caller has to
 * remember to special-case it.
 *
 * Reading is not done here. The reservation question is asked through
 * App\Service\LoginGenerator::loginTaken(), which already had to consult two other sources and is
 * where a caller expects it; this class only answers who a login belongs to, so that method can
 * make its decision.
 */
class UserLoginHistory
{
    public function __construct(
        private readonly UserLoginRepository $logins,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Makes `$login` the login `$user` answers to, and writes down what that displaced.
     *
     * **It writes down the login being left, not only the one being taken**, and that is the whole
     * reason this is a method rather than two lines at the call site. An account that predates this
     * table has no row at all; releasing "whatever is currently open" would then close nothing and
     * quietly lose the very login the rename displaced - which is the bug the table exists to fix.
     * So the outgoing login is inserted first if it is missing, undated, because nobody recorded
     * when it was taken and inventing a date would be worse than admitting that.
     *
     * Idempotent, which matters more here than it looks: the rename is applied by two callers a
     * minute apart (the fiche's polling and `app:ldap:apply-account-requests`), and the second one
     * arriving must be a no-op rather than a duplicate row or a released current login.
     *
     * **Call it before `User::setUsername()`** - it reads the outgoing login from there.
     *
     * Nothing is flushed: the caller owns the transaction. The rename in particular has to land in
     * the same write as `User::$username`, or a crash between the two would leave the account
     * answering to a login its own history says it gave up.
     */
    public function record(User $user, string $login, ?\DateTimeImmutable $at = null): UserLogin
    {
        $at ??= new \DateTimeImmutable();
        $holder = $this->logins->findOneByLogin($login);

        if (null !== $holder && $holder->getUser() !== $user) {
            // Refused rather than silently moved: the unique index would stop it anyway, and a
            // caller that reaches this has skipped the availability check every screen runs first.
            throw new \LogicException(sprintf('Login "%s" already belongs to another account.', $login));
        }

        $outgoing = $user->getUsername();

        if ($outgoing !== $login && null === $this->ownEntry($user, $outgoing)) {
            $this->add($user, $outgoing, null);
        }

        // Everything this account answered to until now is closed - the outgoing login just written
        // down included, and any older row a half-applied rename may have left open. Read from the
        // collection rather than re-queried: the row above is persisted but not flushed, and a
        // repository would not see it yet.
        foreach ($user->getLoginHistory() as $entry) {
            if ($entry->getLogin() !== $login && $entry->isCurrent()) {
                $entry->setReleasedAt($at);
            }
        }

        // Coming back to a login this account held before revives its row; nothing is ever
        // duplicated, which is what keeps the unique index out of the way of a reversal.
        $entry = $this->ownEntry($user, $login) ?? $this->add($user, $login, $at);
        $entry->setReleasedAt(null);

        return $entry;
    }

    /** Who holds this login, currently or in the past - null when nobody ever has. */
    public function holderOf(string $login): ?User
    {
        return $this->logins->findOneByLogin($login)?->getUser();
    }

    /**
     * Every login the account has ever answered to, oldest first.
     *
     * @return list<UserLogin>
     */
    public function historyFor(User $user): array
    {
        return $this->logins->findHistoryFor($user);
    }

    private function ownEntry(User $user, string $login): ?UserLogin
    {
        foreach ($user->getLoginHistory() as $entry) {
            if ($entry->getLogin() === $login) {
                return $entry;
            }
        }

        return null;
    }

    private function add(User $user, string $login, ?\DateTimeImmutable $assignedAt): UserLogin
    {
        $entry = new UserLogin($user, $login, $assignedAt);
        $user->addLoginHistoryEntry($entry);
        $this->entityManager->persist($entry);

        return $entry;
    }
}
