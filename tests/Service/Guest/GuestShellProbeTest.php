<?php

declare(strict_types=1);

namespace App\Tests\Service\Guest;

use App\Service\Guest\GuestShellProbe;
use PHPUnit\Framework\TestCase;

/**
 * Proving that a session which opened can actually run a command.
 *
 * The two are not the same thing, and the gap between them cost a day. Debian and Ubuntu cloud
 * images ship with cloud-init's `disable_root` on, which copies the SSH keys into **root's**
 * authorized_keys behind a forced command:
 *
 *     no-port-forwarding,no-agent-forwarding,no-X11-forwarding,command="echo 'Please login as the
 *     user \"debian\" rather than the user \"root\".';echo;sleep 10;exit 142" ssh-ed25519 AAAA…
 *
 * The key is therefore *accepted* - the login succeeds and the machine looks reachable - but every
 * command is replaced by that message, ten seconds of sleep and exit 142. Nothing runs, nothing
 * says so, and each attempt costs ten seconds until the page is killed by PHP's time limit.
 *
 * Hence a probe that checks what came back rather than that something came back.
 */
class GuestShellProbeTest extends TestCase
{
    public function testASessionThatEchoesTheMarkerIsUsable(): void
    {
        self::assertTrue(GuestShellProbe::provesCommandsRun(GuestShellProbe::MARKER."\n"));
    }

    /** Shells greet, print motds and warn about locales; the marker only has to be in there. */
    public function testSurroundingNoiseDoesNotMatter(): void
    {
        self::assertTrue(GuestShellProbe::provesCommandsRun("Welcome to Debian\n".GuestShellProbe::MARKER."\n"));
    }

    /**
     * The case this exists for, with the message exactly as the image sends it.
     */
    public function testTheCloudImageRootRefusalIsCaught(): void
    {
        $answer = "Please login as the user \"debian\" rather than the user \"root\".\n\n";

        self::assertFalse(GuestShellProbe::provesCommandsRun($answer));
    }

    public function testASilentSessionIsNotProofOfAnything(): void
    {
        self::assertFalse(GuestShellProbe::provesCommandsRun(''));
    }

    /**
     * What the administrator is shown: the machine's own words, because they name the fix. Trimmed
     * so a motd cannot push the useful line out of a log entry.
     */
    public function testTheAnswerIsQuotedBackForTheLog(): void
    {
        $summary = GuestShellProbe::describe("Please login as the user \"debian\" rather than the user \"root\".\n\n");

        self::assertStringContainsString('rather than the user "root"', $summary);
    }

    public function testASilentAnswerIsDescribedRatherThanLeftBlank(): void
    {
        self::assertNotSame('', GuestShellProbe::describe("  \n "));
    }

    /** A screenful of motd must not become a screenful of log. */
    public function testAVeryLongAnswerIsCutDown(): void
    {
        self::assertLessThanOrEqual(200, \strlen(GuestShellProbe::describe(str_repeat('x', 5000))));
    }
}
