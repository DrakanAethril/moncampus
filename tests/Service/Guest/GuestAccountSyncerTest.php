<?php

declare(strict_types=1);

namespace App\Tests\Service\Guest;

use App\Enum\GuestAccountOrigin;
use App\Service\Guest\DesiredAccount;
use App\Service\Guest\GuestAccountSyncer;
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
            static fn (string $login): DesiredAccount => new DesiredAccount($login, GuestAccountOrigin::Member, sudo: true),
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
            [new DesiredAccount('prof', GuestAccountOrigin::Fixed, sudo: true)],
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

    public function testAskingAboutNobodyRunsNothing(): void
    {
        $shell = $this->shell();

        self::assertSame([], $this->syncer()->existingLogins($shell, []));
        self::assertSame([], $shell->commands);
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

    public function __construct(private readonly string $output = '')
    {
    }

    public function run(string $command): GuestCommandResult
    {
        $this->commands[] = $command;

        return new GuestCommandResult($this->output, 0);
    }

    public function disconnect(): void
    {
    }
}
