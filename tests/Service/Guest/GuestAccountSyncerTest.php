<?php

declare(strict_types=1);

namespace App\Tests\Service\Guest;

use App\Enum\GuestAccountOrigin;
use App\Service\Guest\DesiredAccount;
use App\Service\Guest\GuestAccountSyncer;
use App\Service\Guest\GuestCommandFailedException;
use App\Service\Guest\GuestCommandResult;
use App\Service\Guest\GuestShell;
use App\Service\Guest\PasswordGenerator;
use App\Service\Guest\UnixLogin;
use PHPUnit\Framework\TestCase;

/**
 * The account difference, and what applying it actually sends into a machine.
 *
 * The SSH session sits behind App\Service\Guest\GuestShell precisely so this can be judged without
 * a virtual machine: the double below records the commands it is given, which is the only way to
 * pin the things that matter here - that a password never reaches a command line, that sudo is
 * asked for both group names, that a `manual` account is left completely alone.
 *
 * The four buckets are four different decisions, and the two that carry the design are the ones
 * that do *nothing*: `unchanged` is what makes a second run a no-op, and `untouched` is what stops
 * the console deleting an account a student made for their own project.
 */
class GuestAccountSyncerTest extends TestCase
{
    private function syncer(): GuestAccountSyncer
    {
        return new GuestAccountSyncer(new PasswordGenerator(), new UnixLogin());
    }

    private function shell(string $output = ''): GuestShell&RecordingShell
    {
        return new RecordingShell($output);
    }

    /** @return list<DesiredAccount> */
    private function desired(string ...$logins): array
    {
        return array_map(
            static fn (string $login): DesiredAccount => new DesiredAccount($login, GuestAccountOrigin::Member),
            $logins,
        );
    }

    // --- the difference ---------------------------------------------------------------------

    public function testAnEmptyMachineNeedsEveryAccountCreated(): void
    {
        $plan = $this->syncer()->plan($this->desired('marie-dupont', 'jean-martin'), []);

        self::assertSame(2, $plan->createCount());
        self::assertSame(0, $plan->removeCount());
        self::assertSame([], $plan->unchanged);
    }

    public function testASecondRunHasNothingToDo(): void
    {
        // The property the whole class is built around: the difference is computed from what the
        // machine reports, not from what MonCampus did last time.
        $plan = $this->syncer()->plan(
            $this->desired('marie-dupont', 'jean-martin'),
            ['marie-dupont' => GuestAccountOrigin::Member, 'jean-martin' => GuestAccountOrigin::Member],
        );

        self::assertTrue($plan->isEmpty());
        self::assertCount(2, $plan->unchanged);
    }

    public function testAnArrivingStudentIsTheOnlyThingCreated(): void
    {
        $plan = $this->syncer()->plan(
            $this->desired('marie-dupont', 'jean-martin', 'lea-bernard'),
            ['marie-dupont' => GuestAccountOrigin::Member, 'jean-martin' => GuestAccountOrigin::Member],
        );

        self::assertSame(1, $plan->createCount());
        self::assertSame('lea-bernard', $plan->toCreate[0]->login);
    }

    public function testALeavingStudentIsProposedForRemovalRatherThanRemoved(): void
    {
        $plan = $this->syncer()->plan(
            $this->desired('marie-dupont'),
            ['marie-dupont' => GuestAccountOrigin::Member, 'jean-martin' => GuestAccountOrigin::Member],
        );

        self::assertSame(['jean-martin'], $plan->toRemove);
    }

    public function testAnAccountSomebodyMadeInsideTheMachineIsNeverTouched(): void
    {
        // The rule worth guarding hardest: a console that quietly deleted a student's own account
        // would be worse than one that never synchronised at all.
        $plan = $this->syncer()->plan(
            $this->desired('marie-dupont'),
            ['marie-dupont' => GuestAccountOrigin::Member, 'projet-perso' => GuestAccountOrigin::Manual],
        );

        self::assertSame([], $plan->toRemove);
        self::assertSame(['projet-perso'], $plan->untouched);
    }

    public function testAnAccountTheMachineHasAndMonCampusNeverRecordedIsNotTouchedEither(): void
    {
        $plan = $this->syncer()->plan($this->desired('marie-dupont'), ['marie-dupont' => GuestAccountOrigin::Member, 'stranger' => null]);

        self::assertSame([], $plan->toRemove);
        self::assertSame(['stranger'], $plan->untouched);
    }

    public function testAnAccountDeliberatelyKeptStopsBeingProposed(): void
    {
        // Without this, the same removal is proposed at every single run and the screen trains
        // people to ignore it.
        $plan = $this->syncer()->plan(
            $this->desired('marie-dupont'),
            ['marie-dupont' => GuestAccountOrigin::Member, 'jean-martin' => GuestAccountOrigin::Member],
            kept: ['jean-martin'],
        );

        self::assertSame([], $plan->toRemove);
        self::assertSame(['jean-martin'], $plan->untouched);
    }

    public function testAFixedAccountThatIsStillWantedIsUnchanged(): void
    {
        $plan = $this->syncer()->plan(
            [new DesiredAccount('prof', GuestAccountOrigin::Fixed)],
            ['prof' => GuestAccountOrigin::Fixed],
        );

        self::assertTrue($plan->isEmpty());
    }

    // --- what actually gets sent ------------------------------------------------------------

    public function testCreatingAnAccountAddsItGivesItAPasswordAndSudo(): void
    {
        $shell = $this->shell();
        $plan = $this->syncer()->plan($this->desired('marie-dupont'), []);

        $passwords = $this->syncer()->apply($shell, $plan);

        self::assertArrayHasKey('marie-dupont', $passwords);
        self::assertStringContainsString("useradd --create-home --shell '/bin/bash' 'marie-dupont'", $shell->commands[0]);
        self::assertStringContainsString('chpasswd', $shell->commands[1]);
        // Both group names: Debian says sudo, RHEL says wheel, and the failing one costs nothing.
        self::assertStringContainsString('sudo', $shell->commands[2]);
        self::assertStringContainsString('wheel', $shell->commands[2]);
    }

    public function testThePasswordNeverAppearsOnACommandLine(): void
    {
        // It reaches chpasswd on stdin, so it never shows up in the process list of a machine
        // students are logged into.
        $shell = $this->shell();
        $plan = $this->syncer()->plan($this->desired('marie-dupont'), []);

        $passwords = $this->syncer()->apply($shell, $plan);
        $password = $passwords['marie-dupont'];

        foreach ($shell->commands as $command) {
            if (str_contains($command, 'chpasswd')) {
                self::assertStringStartsWith('printf', $command, 'the password is piped in, not passed as an argument');
            }
        }

        self::assertStringContainsString($password, implode(' ', $shell->commands), 'sanity: it is the password we generated');
    }

    public function testAnSshKeyIsInstalledWithTheRightOwnershipAndModes(): void
    {
        $shell = $this->shell();
        $plan = $this->syncer()->plan($this->desired('marie-dupont'), []);

        $this->syncer()->apply($shell, $plan, 'ssh-ed25519 AAAAC3Nz moncampus@platform');
        $installed = implode(' ', $shell->commands);

        self::assertStringContainsString('install -d -m 700', $installed);
        self::assertStringContainsString('chmod 600', $installed);
        self::assertStringContainsString('authorized_keys', $installed);
    }

    public function testNoSshKeyMeansNoAuthorizedKeysCommand(): void
    {
        $shell = $this->shell();
        $this->syncer()->apply($shell, $this->syncer()->plan($this->desired('marie-dupont'), []));

        self::assertStringNotContainsString('authorized_keys', implode(' ', $shell->commands));
    }

    public function testAnUnusableLoginIsSkippedRatherThanSentIntoAShell(): void
    {
        $shell = $this->shell();
        $plan = $this->syncer()->plan([new DesiredAccount('Marie Dupont; rm -rf /', GuestAccountOrigin::Member)], []);

        $passwords = $this->syncer()->apply($shell, $plan);

        self::assertSame([], $passwords);
        self::assertSame([], $shell->commands);
    }

    public function testRemovingTakesTheHomeDirectoryWithIt(): void
    {
        $shell = $this->shell();
        $this->syncer()->remove($shell, 'jean-martin');

        self::assertSame(["userdel --remove 'jean-martin'"], $shell->commands);
    }

    public function testRemovingRefusesALoginThatIsNotOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->syncer()->remove($this->shell(), 'jean; reboot');
    }

    public function testResettingAPasswordAnswersTheNewOne(): void
    {
        // The counterpart of never storing them: "I lost it" has a one-click answer, which is what
        // makes "shown once" acceptable.
        $shell = $this->shell();
        $password = $this->syncer()->resetPassword($shell, 'marie-dupont');

        self::assertNotSame('', $password);
        self::assertStringContainsString('chpasswd', $shell->commands[0]);
    }

    public function testReadingTheMachineKeepsOnlyTheLoginsItReports(): void
    {
        $shell = $this->shell("marie-dupont\njean-martin\n");

        self::assertSame(
            ['marie-dupont', 'jean-martin'],
            $this->syncer()->existingLogins($shell, ['marie-dupont', 'jean-martin', 'lea-bernard']),
        );
    }

    public function testReadingTheMachineIgnoresAnythingItDidNotAskAbout(): void
    {
        // getent's output is not a promise; a line the caller never asked about is noise.
        $shell = $this->shell("marie-dupont\nroot\n");

        self::assertSame(['marie-dupont'], $this->syncer()->existingLogins($shell, ['marie-dupont']));
    }

    /**
     * The defect this pins is the one that made the Comptes screen list the same six accounts as
     * declared *and* as still to create, on a machine that had them all.
     *
     * A probe that finds nothing and a probe that never ran both answer an empty list, so the
     * screen could not tell them apart - and the loop's own exit status used to be meaningless
     * anyway (it is the last iteration's, so a last login that simply does not exist made a
     * perfectly good probe look failed). Written with `if … fi` the status says what it says, and
     * asking for it is what turns a silent nothing into a refusal somebody reads.
     */
    public function testAProbeThatCouldNotRunIsRaisedRatherThanReadAsAnEmptyMachine(): void
    {
        $shell = new RecordingShell(failingNeedle: 'getent');

        $this->expectException(GuestCommandFailedException::class);

        $this->syncer()->existingLogins($shell, ['marie-dupont']);
    }

    public function testTheProbeAsksAboutEachLoginWithoutItsOwnStatusDependingOnTheLastOne(): void
    {
        $shell = $this->shell("marie-dupont\n");
        $this->syncer()->existingLogins($shell, ['marie-dupont', 'absent']);

        // `if … fi` and not `&&`: see the test above for what the difference buys.
        self::assertStringContainsString('if getent passwd', $shell->commands[0]);
        self::assertStringNotContainsString('&& echo', $shell->commands[0]);
    }

    /**
     * Every account, with no setting anywhere to say otherwise: these are the machines of a
     * practical class, the person in front of one is the only person on it, and an account that
     * cannot install a package cannot do the exercise.
     *
     * Being in the `sudo` group is what *allows* sudo; on Debian it also means being asked for a
     * password. These accounts have one generated, sent to the machine and forgotten on the spot -
     * nobody knows it - so without the rule sudo would be allowed and unusable at the same time.
     */
    public function testEveryAccountCanAdministerItsOwnMachineWithoutBeingAskedForAPassword(): void
    {
        $shell = new RecordingShell();
        $this->syncer()->apply($shell, $this->syncer()->plan([new DesiredAccount('marie-dupont', GuestAccountOrigin::Member)], []));

        $rule = implode("\n", $shell->commands);

        self::assertStringContainsString('marie-dupont ALL=(ALL) NOPASSWD:ALL', $rule);
        self::assertStringContainsString('/etc/sudoers.d/90-moncampus-marie-dupont', $rule);
    }

    /**
     * A malformed file in `/etc/sudoers.d` does not break itself, it breaks sudo entirely - on a
     * machine whose only administrative way in is sudo. So it is judged before it lands, and a
     * refusal leaves the machine as it was.
     */
    public function testTheSudoersRuleIsJudgedByVisudoBeforeItIsInstalled(): void
    {
        $shell = new RecordingShell();
        $this->syncer()->apply($shell, $this->syncer()->plan([new DesiredAccount('marie-dupont', GuestAccountOrigin::Member)], []));

        $sudoers = array_values(array_filter($shell->commands, static fn (string $c): bool => str_contains($c, 'sudoers.d')));

        self::assertCount(1, $sudoers);
        self::assertStringContainsString('visudo -c -f', $sudoers[0]);
        // Judged on a temporary file, then installed: the order is the whole protection.
        self::assertLessThan(
            strpos($sudoers[0], 'install -m 440'),
            strpos($sudoers[0], 'visudo -c -f'),
            'the rule must be validated before it reaches /etc/sudoers.d',
        );
    }

    public function testEveryAccountJoinsTheDockerGroup(): void
    {
        $shell = new RecordingShell();
        $this->syncer()->apply($shell, $this->syncer()->plan([new DesiredAccount('marie-dupont', GuestAccountOrigin::Member)], []));

        $docker = array_values(array_filter($shell->commands, static fn (string $c): bool => str_contains($c, 'docker')));

        self::assertCount(1, $docker);
        self::assertStringContainsString("usermod -aG docker 'marie-dupont'", $docker[0]);
        // Created when missing rather than the membership being skipped: Docker's own packaging
        // adopts a group that already holds the name, so the order of installs stops mattering.
        self::assertStringContainsString('groupadd docker', $docker[0]);
    }

    public function testAskingAboutNobodyRunsNothing(): void
    {
        $shell = $this->shell();

        self::assertSame([], $this->syncer()->existingLogins($shell, []));
        self::assertSame([], $shell->commands);
    }

    /**
     * The silence that cost a day: `useradd` came back non-zero, its message went into an output
     * nobody read, and the batch went on to report the account as created. Nothing anywhere said
     * the machine was empty.
     */
    public function testACommandTheMachineRefusesIsRaisedRatherThanIgnored(): void
    {
        $shell = new RecordingShell(failingNeedle: 'useradd');

        $this->expectException(GuestCommandFailedException::class);
        // The machine's own words: they name the cause far better than anything said on its behalf.
        $this->expectExceptionMessageMatches('/cannot open \/etc\/passwd/');

        $this->syncer()->apply($shell, $this->syncer()->plan($this->desired('marie-dupont'), []));
    }

    /**
     * A session that ends before the verdict comes back is not a failure - see GuestCommandResult.
     * A post-installation script that reboots does exactly this, and treating it as an error would
     * have administrators chasing a problem that does not exist.
     */
    public function testACommandWhoseVerdictNeverCameBackIsNotAFailure(): void
    {
        $shell = new RecordingShell(failingNeedle: 'useradd', undetermined: true);

        $passwords = $this->syncer()->apply($shell, $this->syncer()->plan($this->desired('marie-dupont'), []));

        self::assertArrayHasKey('marie-dupont', $passwords);
    }
}

/**
 * A GuestShell that records rather than connects. The point of the interface, and the only reason
 * every rule above can be judged without a virtual machine.
 */
class RecordingShell implements GuestShell
{
    /** @var list<string> */
    public array $commands = [];

    /**
     * @param ?string $failingNeedle a command containing this refuses, the way a real machine does:
     *                                a message on the output and a non-zero status
     * @param bool    $undetermined   the refusal comes back with no status at all, as a machine that
     *                                reboots mid-command does
     */
    public function __construct(
        private readonly string $output = '',
        private readonly ?string $failingNeedle = null,
        private readonly bool $undetermined = false,
    ) {
    }

    public function run(string $command): GuestCommandResult
    {
        $this->commands[] = $command;

        if (null !== $this->failingNeedle && str_contains($command, $this->failingNeedle)) {
            return new GuestCommandResult('useradd: cannot open /etc/passwd', $this->undetermined ? null : 1);
        }

        return new GuestCommandResult($this->output, 0);
    }

    public function disconnect(): void
    {
    }
}
