<?php

declare(strict_types=1);

namespace App\Service\Guest;

use App\Enum\GuestAccountOrigin;

/**
 * Brings a machine's accounts in line with what is wanted - and works out what that means first.
 *
 * The whole class is built around one property: **it is rejouable**. It computes a difference from
 * what the machine currently reports rather than from what MonCampus did last time, so running it
 * twice does nothing the second time, running it after a student joins creates one account, and
 * running it on a machine somebody has been editing by hand does not fight them.
 *
 * cloud-init cannot do this. It applies its configuration once, at the first boot: it can lay down
 * the accounts a machine is born with and it can never add an arriving student, remove a leaving
 * one, or push a regenerated key. That is the reason this exists at all.
 *
 * Three rules that are not obvious and are all deliberate:
 *
 *  - **`manual` accounts are never touched.** Somebody made them inside the machine. A console that
 *    quietly deleted the account a student created for their own project would be worse than one
 *    that never synchronised.
 *  - **removals are proposed, never performed.** Deleting a home directory is not a thing to do on
 *    a schedule; `plan()` names them and an administrator applies them one at a time.
 *  - **passwords are generated, handed over once, and never stored.** They are returned by
 *    apply() and that is the only time they exist outside the machine. It is a tenable choice only
 *    because resetting one is a button away - the platform key makes it trivial.
 */
class GuestAccountSyncer
{
    public function __construct(
        private readonly PasswordGenerator $passwordGenerator,
        private readonly UnixLogin $unixLogin,
    ) {
    }

    /**
     * The difference, without touching anything. Pure - given the same two lists it answers the
     * same plan, which is what lets the screen show it before anybody commits to it.
     *
     * @param list<DesiredAccount>                        $desired
     * @param array<string, GuestAccountOrigin|null>      $existing login => how it got there, as
     *                                                              recorded; null for an account
     *                                                              found on the machine that
     *                                                              MonCampus has no record of
     * @param list<string>                                $kept     logins an administrator decided
     *                                                              to keep despite them no longer
     *                                                              being wanted
     */
    public function plan(array $desired, array $existing, array $kept = []): AccountPlan
    {
        $wanted = [];
        foreach ($desired as $account) {
            $wanted[$account->login] = $account;
        }

        $toCreate = [];
        $unchanged = [];

        foreach ($wanted as $login => $account) {
            if (\array_key_exists($login, $existing)) {
                $unchanged[] = $account;
            } else {
                $toCreate[] = $account;
            }
        }

        $toRemove = [];
        $untouched = [];

        foreach ($existing as $login => $origin) {
            if (isset($wanted[$login])) {
                continue;
            }

            // Never ours to remove: made inside the machine, or already judged and kept.
            if (GuestAccountOrigin::Manual === $origin || null === $origin || \in_array($login, $kept, true)) {
                $untouched[] = $login;
                continue;
            }

            // A fixed account is not tied to a roster, so its disappearance from the wanted list
            // means the batch's definition changed - which is a removal worth proposing like any
            // other, and never one to perform unasked.
            $toRemove[] = $login;
        }

        return new AccountPlan($toCreate, $unchanged, $toRemove, $untouched);
    }

    /**
     * Reads the machine: which of the logins MonCampus knows about actually exist there.
     *
     * `getent passwd` rather than parsing /etc/passwd: it answers for LDAP and SSSD users too, and
     * a machine joined to nothing answers exactly the same.
     *
     * @param list<string> $logins
     *
     * @return list<string> those that exist
     *
     * @throws GuestUnreachableException
     */
    public function existingLogins(GuestShell $shell, array $logins): array
    {
        if ([] === $logins) {
            return [];
        }

        $safe = array_map(static fn (string $login): string => escapeshellarg($login), $logins);
        $result = $shell->run(\sprintf('for u in %s; do getent passwd "$u" >/dev/null && echo "$u"; done', implode(' ', $safe)));

        $found = [];
        foreach (explode("\n", $result->output) as $line) {
            $line = trim($line);

            if ('' !== $line && \in_array($line, $logins, true)) {
                $found[] = $line;
            }
        }

        return $found;
    }

    /**
     * Creates the accounts of a plan and answers their passwords, once.
     *
     * @return array<string, string> login => password. **The only time these exist outside the
     *                               machine.** Nothing here stores them, and the caller is expected
     *                               to show them and forget them.
     *
     * @throws GuestUnreachableException
     */
    public function apply(GuestShell $shell, AccountPlan $plan, ?string $publicKey = null): array
    {
        $passwords = [];

        foreach ($plan->toCreate as $account) {
            if (!$this->unixLogin->isValid($account->login)) {
                continue;
            }

            $password = $this->passwordGenerator->generate();

            foreach ($this->createCommands($account, $password, $publicKey) as $command) {
                $shell->run($command);
            }

            $passwords[$account->login] = $password;
        }

        return $passwords;
    }

    /**
     * Removes one account, with its home directory. Called one at a time from the screen, never in
     * a loop over a plan - see the class docblock.
     *
     * @throws GuestUnreachableException
     */
    public function remove(GuestShell $shell, string $login): GuestCommandResult
    {
        if (!$this->unixLogin->isValid($login)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid login.', $login));
        }

        return $shell->run(\sprintf('userdel --remove %s', escapeshellarg($login)));
    }

    /**
     * Sets a new password on an existing account and answers it.
     *
     * The counterpart of never storing them: "I have lost it" has an answer that takes one click,
     * which is what makes "shown once" acceptable in the first place.
     *
     * @throws GuestUnreachableException
     */
    public function resetPassword(GuestShell $shell, string $login): string
    {
        if (!$this->unixLogin->isValid($login)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid login.', $login));
        }

        $password = $this->passwordGenerator->generate();
        $shell->run($this->setPasswordCommand($login, $password));

        return $password;
    }

    /**
     * @return list<string>
     */
    private function createCommands(DesiredAccount $account, string $password, ?string $publicKey): array
    {
        $login = escapeshellarg($account->login);

        $commands = [
            // --create-home and a real shell: these are accounts people log into.
            \sprintf('useradd --create-home --shell %s %s', escapeshellarg($account->shell), $login),
            $this->setPasswordCommand($account->login, $password),
        ];

        if ($account->sudo) {
            // Both group names, because Debian calls it sudo and RHEL calls it wheel, and a failed
            // usermod on the group that does not exist costs nothing.
            $commands[] = \sprintf('usermod -aG sudo %s || usermod -aG wheel %s', $login, $login);
        }

        if (null !== $publicKey && '' !== $publicKey) {
            $commands[] = \sprintf(
                'install -d -m 700 -o %1$s -g %1$s /home/%2$s/.ssh && printf %%s %3$s >> /home/%2$s/.ssh/authorized_keys && chown %1$s:%1$s /home/%2$s/.ssh/authorized_keys && chmod 600 /home/%2$s/.ssh/authorized_keys',
                $login,
                $account->login,
                escapeshellarg($publicKey."\n"),
            );
        }

        return $commands;
    }

    /**
     * chpasswd rather than `passwd`, which insists on a terminal - and the password reaches it on
     * stdin rather than in the command line, so it never appears in the process list of a machine
     * students are logged into.
     */
    private function setPasswordCommand(string $login, #[\SensitiveParameter] string $password): string
    {
        return \sprintf('printf %%s %s | chpasswd', escapeshellarg($login.':'.$password));
    }
}
