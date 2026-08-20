<?php

declare(strict_types=1);

namespace App\Tests\Service\Guest;

use App\Service\Guest\GuestCommandLine;
use PHPUnit\Framework\TestCase;

/**
 * How a command is wrapped before it is sent into a machine.
 *
 * Everything MonCampus runs inside a guest is administrative - creating accounts, setting
 * passwords, running a post-installation script - so the question is never *whether* to elevate but
 * only whether it is needed. Logged in as root it is not; logged in as the account a cloud image
 * actually allows (`debian`, `ubuntu`), every command needs sudo.
 *
 * Wrapping happens here, in one place, rather than at each call site. A command that someone
 * forgets to elevate does not fail loudly: it returns "permission denied" into an output nobody
 * reads, and the account it was supposed to create simply never exists.
 */
class GuestCommandLineTest extends TestCase
{
    public function testAsRootNothingIsElevated(): void
    {
        $line = GuestCommandLine::build('useradd marie', 'root');

        self::assertStringNotContainsString('sudo', $line);
        self::assertStringContainsString('useradd marie', $line);
    }

    public function testAsAnyOtherAccountTheCommandIsElevated(): void
    {
        $line = GuestCommandLine::build('useradd marie', 'debian');

        self::assertStringContainsString('sudo -n', $line);
        self::assertStringContainsString('useradd marie', $line);
    }

    /**
     * `-n` and not a bare sudo: a machine whose sudoers wants a password must fail at once and say
     * so, never sit there waiting for one that will not come. That is the difference between a
     * failure in the log and a deployment that hangs until PHP kills it.
     */
    public function testSudoNeverWaitsForAPassword(): void
    {
        self::assertStringContainsString('sudo -n', GuestCommandLine::build('id', 'debian'));
    }

    /** Both ways in: stdin closed and stderr folded into the output, or a prompt becomes a hang. */
    public function testAnUnattendedCommandCannotStopToAsk(): void
    {
        foreach (['root', 'debian'] as $username) {
            $line = GuestCommandLine::build('apt-get install nginx', $username);

            self::assertStringContainsString('< /dev/null', $line, $username);
            self::assertStringContainsString('2>&1', $line, $username);
            self::assertStringContainsString('DEBIAN_FRONTEND=noninteractive', $line, $username);
        }
    }

    /**
     * The commands this application sends are not single words: loops, pipes, redirections and a
     * heredoc carrying a whole script. Elevating means handing them to a shell, and a shell that is
     * given them unquoted would take half of them for its own.
     */
    public function testACompoundCommandSurvivesBeingElevated(): void
    {
        $command = "cat > /tmp/s <<'EOF'\necho \"hello\"\nEOF";
        $line = GuestCommandLine::build($command, 'debian');

        self::assertStringContainsString('/bin/sh -c ', $line);
        // Everything the caller wrote is still in there, quoting included.
        self::assertStringContainsString('echo', $line);
        self::assertStringContainsString('EOF', $line);
    }

    /** A password can hold a quote; it must reach the machine as itself and not end the argument. */
    public function testASingleQuoteInTheCommandDoesNotBreakOutOfIt(): void
    {
        $line = GuestCommandLine::build("chpasswd <<< 'marie:pa'\''ss'", 'debian');

        self::assertStringStartsWith('sudo -n /bin/sh -c ', $line);
        self::assertStringEndsWith('< /dev/null 2>&1', $line);
    }
}
