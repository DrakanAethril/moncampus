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
        // `if … then … fi` rather than `&&`: the loop's exit status is the last iteration's, so
        // with `&&` a last login that simply does not exist made the whole command look failed -
        // and nothing could then tell that apart from a command that never ran. Written this way
        // the status means what it says, which is what makes the check below worth making.
        $command = \sprintf(
            'for u in %s; do if getent passwd "$u" >/dev/null 2>&1; then echo "$u"; fi; done',
            implode(' ', $safe),
        );
        $result = $shell->run($command);

        // A probe that finds nothing and a probe that never ran read identically - an empty list -
        // and the second is how every declared account came to show as permanently « à créer ».
        // The verdict is asked for rather than assumed.
        self::mustSucceed($command, $result);

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
    /**
     * @param bool $readAloud whether the passwords are going to be shown to somebody. True for the
     *                        machine's own Comptes screen, which prints them once to be handed out;
     *                        false for batch provisioning, where nothing ever displays them, and a
     *                        password built to survive being spoken across a room would only be
     *                        weaker for no gain.
     */
    public function apply(GuestShell $shell, AccountPlan $plan, ?string $publicKey = null, bool $readAloud = true): array
    {
        $passwords = [];

        foreach ($plan->toCreate as $account) {
            if (!$this->unixLogin->isValid($account->login)) {
                continue;
            }

            $password = $readAloud
                ? $this->passwordGenerator->generate()
                : $this->passwordGenerator->generateStrong();

            foreach ($this->createCommands($account, $password, $publicKey) as $command) {
                self::mustSucceed($command, $shell->run($command));
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

        $command = \sprintf('userdel --remove %s', escapeshellarg($login));
        $result = $shell->run($command);
        self::mustSucceed($command, $result);

        return $result;
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
        $command = $this->setPasswordCommand($login, $password);
        self::mustSucceed($command, $shell->run($command));

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
            $commands[] = $this->passwordlessSudoCommand($account->login);
        }

        $commands[] = $this->dockerGroupCommand($account->login);

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
     * Passwordless sudo for an account that has been granted sudo at all.
     *
     * The group membership above is what *allows* sudo; on a Debian machine it also means being
     * asked for a password. These accounts have one generated, sent to the machine and forgotten on
     * the spot - nobody knows it, so being asked for it makes sudo unusable rather than safer.
     *
     * **Validated before it lands.** A malformed file in `/etc/sudoers.d` does not break itself, it
     * breaks sudo *entirely* - on a machine whose only administrative way in is sudo. So it is
     * written to a temporary file, `visudo -c` judges that file, and only a file that passes is
     * installed. A refusal leaves the machine exactly as it was.
     *
     * `install -m 440` rather than a redirection plus chmod: it is one step, and it is the mode
     * sudo insists on. The name carries no dot, which `/etc/sudoers.d` would silently ignore.
     */
    private function passwordlessSudoCommand(string $login): string
    {
        $file = \sprintf('/etc/sudoers.d/90-moncampus-%s', $login);
        $rule = \sprintf('%s ALL=(ALL) NOPASSWD:ALL', $login);

        return \sprintf(
            'tmp=$(mktemp) && printf \'%%s\\n\' %s > "$tmp" && visudo -c -f "$tmp" >/dev/null && install -m 440 -o root -g root "$tmp" %s && rm -f "$tmp"',
            escapeshellarg($rule),
            escapeshellarg($file),
        );
    }

    /**
     * The account joins `docker`, so the student can drive the daemon without sudo for every call.
     *
     * The group is created when it is missing rather than the membership being skipped. Docker's
     * own packaging creates `docker` only if nothing holds the name, so a group made here is the
     * one it will adopt - which means the order stops mattering: a machine that gets Docker after
     * its accounts still has its students in the group, where a `getent || skip` would have left
     * them out with nothing saying so.
     */
    private function dockerGroupCommand(string $login): string
    {
        return \sprintf(
            'if ! getent group docker >/dev/null 2>&1; then groupadd docker; fi; usermod -aG docker %s',
            escapeshellarg($login),
        );
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

    /**
     * Every command sent here is one whose whole point is its effect: an account created, a password
     * set, a key installed. A refusal must therefore stop the caller rather than be counted as done.
     *
     * An *undetermined* result is not a refusal - see App\Service\Guest\GuestCommandResult. The
     * session ending before the verdict comes back is what a machine that reboots does, and treating
     * it as an error would have administrators chasing something that did not happen.
     *
     * @throws GuestCommandFailedException
     */
    private static function mustSucceed(string $command, GuestCommandResult $result): void
    {
        if (!$result->isUndetermined() && !$result->isSuccess()) {
            throw GuestCommandFailedException::of($command, $result);
        }
    }
}
