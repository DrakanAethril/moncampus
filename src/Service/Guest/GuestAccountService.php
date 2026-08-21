<?php

declare(strict_types=1);

namespace App\Service\Guest;

use App\Entity\GuestAccount;
use App\Entity\ProxmoxHost;
use App\Entity\ProxmoxOperation;
use App\Entity\User;
use App\Enum\GuestAccountOrigin;
use App\Enum\GuestAccountState;
use App\Enum\ProxmoxAction;
use App\Repository\GuestAccountRepository;
use App\Service\Proxmox\ProxmoxOperationTracker;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The database side of guest accounts: what MonCampus records, and how a synchronisation run
 * updates it.
 *
 * App\Service\Guest\GuestAccountSyncer does the thinking and talks to the machine;
 * this holds the rows, so that the screen can show a difference before anybody commits to it and
 * so that the "kept" decision survives to the next run.
 *
 * **Passwords come back from here exactly once.** They are returned to the caller, shown on a
 * screen made to be printed or read out, and never written down. That is tenable only because
 * resetting one is a click - the platform key gets back in without anybody's password.
 */
class GuestAccountService
{
    public function __construct(
        private readonly GuestAccountRepository $repository,
        private readonly GuestAccountSyncer $syncer,
        private readonly ProxmoxOperationTracker $tracker,
        private readonly PlatformKeyProvider $keyProvider,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Compares what is recorded against what the machine actually has, and updates the recorded
     * states - without creating or removing anything.
     *
     * Called when the accounts screen is opened, which is what makes that screen a *difference*
     * rather than a list of intentions.
     *
     * @throws GuestUnreachableException
     */
    public function refresh(GuestShell $shell, ProxmoxHost $host, string $node, int $vmid): AccountPlan
    {
        $recorded = $this->repository->findForMachine($host, $node, $vmid);
        $logins = array_map(static fn (GuestAccount $account): string => $account->getLogin(), $recorded);
        $present = $this->syncer->existingLogins($shell, $logins);

        $desired = [];
        $existing = [];
        $kept = [];

        foreach ($recorded as $account) {
            $isThere = \in_array($account->getLogin(), $present, true);

            if (GuestAccountState::Kept === $account->getState()) {
                $kept[] = $account->getLogin();
            }

            if ($isThere) {
                $existing[$account->getLogin()] = $account->getOrigin();
            }

            // A removed account is no longer wanted; anything else is.
            if (!\in_array($account->getState(), [GuestAccountState::ToRemove, GuestAccountState::Kept], true)) {
                $desired[] = new DesiredAccount(
                    $account->getLogin(),
                    $account->getOrigin(),
                    $account->getShell(),
                    $account->getUser()?->getId(),
                    $account->getDisplayName(),
                );
            }

            $account->setState($isThere ? GuestAccountState::Present : GuestAccountState::ToCreate);
        }

        $this->entityManager->flush();

        return $this->syncer->plan($desired, $existing, $kept);
    }

    /**
     * Creates what the plan says is missing, records the result, and answers the passwords once.
     *
     * @return array{operation: ProxmoxOperation, passwords: array<string, string>}
     *
     * @throws GuestUnreachableException
     */
    public function apply(
        GuestShell $shell,
        ProxmoxHost $host,
        string $node,
        int $vmid,
        string $guestName,
        AccountPlan $plan,
        ?User $requestedBy,
        bool $readAloud = true,
    ): array {
        $operation = $this->tracker->begin($host, ProxmoxAction::Provision, $requestedBy, $node, $vmid, $guestName, 'qemu');

        try {
            $passwords = $this->syncer->apply($shell, $plan, $this->keyProvider->publicKey(), $readAloud);
        } catch (GuestUnreachableException $exception) {
            $this->tracker->failed($operation, $exception->getMessage());

            throw $exception;
        }

        foreach (array_keys($passwords) as $login) {
            $account = $this->repository->findOneForMachine($host, $node, $vmid, $login);
            $account?->setState(GuestAccountState::Present);
        }

        $operation->markSucceeded(\sprintf(
            '+%d créé(s), %d inchangé(s), %d à retirer',
            \count($passwords),
            \count($plan->unchanged),
            $plan->removeCount(),
        ));

        $this->entityManager->flush();

        return ['operation' => $operation, 'passwords' => $passwords];
    }

    /** Records an account MonCampus wants on a machine. Creating it is a separate, later step. */
    public function declare(
        ProxmoxHost $host,
        string $node,
        int $vmid,
        string $login,
        GuestAccountOrigin $origin,
        ?User $user = null,
        ?string $displayName = null,
    ): GuestAccount {
        $existing = $this->repository->findOneForMachine($host, $node, $vmid, $login);

        if (null !== $existing) {
            return $existing;
        }

        $account = new GuestAccount($host, $node, $vmid, $login);
        $account->setOrigin($origin);

        if (null !== $user) {
            $account->setUser($user);
        } elseif (null !== $displayName) {
            $account->setDisplayName($displayName);
        }

        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    /**
     * Removes an account from the machine and from the record.
     *
     * One at a time, from a button, and never in a loop over a plan: deleting somebody's home
     * directory is not a thing to do on a schedule.
     *
     * @throws GuestUnreachableException
     */
    public function remove(GuestShell $shell, ProxmoxHost $host, string $node, int $vmid, string $login): void
    {
        $this->syncer->remove($shell, $login);

        $account = $this->repository->findOneForMachine($host, $node, $vmid, $login);

        if (null !== $account) {
            $this->entityManager->remove($account);
            $this->entityManager->flush();
        }
    }

    /**
     * Records that an account no longer wanted is being left alone.
     *
     * Without this the same removal is proposed at every run, and a screen that always shows the
     * same suggestion is a screen people stop reading.
     */
    public function keep(ProxmoxHost $host, string $node, int $vmid, string $login): void
    {
        $account = $this->repository->findOneForMachine($host, $node, $vmid, $login);

        if (null === $account) {
            $account = new GuestAccount($host, $node, $vmid, $login);
            $account->setOrigin(GuestAccountOrigin::Manual);
            $this->entityManager->persist($account);
        }

        $account->setState(GuestAccountState::Kept);
        $this->entityManager->flush();
    }

    /**
     * A new password on an existing account, answered once.
     *
     * @throws GuestUnreachableException
     */
    public function resetPassword(GuestShell $shell, string $login): string
    {
        return $this->syncer->resetPassword($shell, $login);
    }

    /**
     * A password its owner chose, set on their own account and recorded as having happened.
     *
     * The operation row is what an administrator sees later: it names the machine and the login and
     * says a password was changed. It does not carry the password, and nothing else here does
     * either - the value reaches the machine and this method forgets it.
     *
     * @throws \InvalidArgumentException when the password is not one
     * @throws GuestUnreachableException
     */
    public function setPassword(
        GuestShell $shell,
        ProxmoxHost $host,
        string $node,
        int $vmid,
        string $login,
        #[\SensitiveParameter] string $password,
        ?User $requestedBy,
    ): void {
        // Validated before the row is opened: a password that is refused never happened, and a log
        // saying otherwise is worse than no log.
        $operation = $this->tracker->begin($host, ProxmoxAction::Provision, $requestedBy, $node, $vmid, null, 'qemu');

        try {
            $this->syncer->setPassword($shell, $login, $password);
        } catch (GuestUnreachableException|\InvalidArgumentException $exception) {
            $this->tracker->failed($operation, $exception->getMessage());

            throw $exception;
        }

        $operation->markSucceeded(\sprintf('mot de passe changé pour %s', $login));
        $this->entityManager->flush();
    }
}
