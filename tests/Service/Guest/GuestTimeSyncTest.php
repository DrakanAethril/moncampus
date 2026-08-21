<?php

declare(strict_types=1);

namespace App\Tests\Service\Guest;

use App\Service\Guest\GuestCommandFailedException;
use App\Service\Guest\GuestTimeSync;
use PHPUnit\Framework\TestCase;

/**
 * Pointing a machine's clock at the gateway of its own VLAN.
 *
 * A school VLAN rarely lets a machine reach the public NTP pool a cloud image ships with, so the
 * clock silently never gets set - and the drift surfaces much later as something nobody connects to
 * time: a certificate refused, an SSH key rejected, a submission dated a day off. The gateway is the
 * one address every machine of a range can always reach.
 *
 * The edit is asserted by **running it** on a real file rather than by reading the string, for the
 * same reason GuestCommandLineTest runs its line: a shell command can contain every substring
 * anybody thought to check and still do the wrong thing to a file.
 */
class GuestTimeSyncTest extends TestCase
{
    public function testTheGatewayBecomesTheOnlyNtpSource(): void
    {
        $conf = $this->chronyConf();

        $this->runScriptOn($conf);

        $written = (string) file_get_contents($conf);

        self::assertStringContainsString('server 10.30.20.254 iburst prefer', $written);
        // Everything the image shipped with is commented rather than deleted: an administrator
        // reading this file must be able to see what was there before.
        self::assertStringContainsString('# pool 2.debian.pool.ntp.org iburst', $written);
        self::assertStringContainsString('# server ntp.example.org iburst', $written);
        // Untouched: this step is about sources, not about the rest of chrony's configuration.
        self::assertStringContainsString('driftfile /var/lib/chrony/chrony.drift', $written);
    }

    /**
     * A machine is deployed once but provisioned as many times as somebody presses the button, so an
     * edit that stacks would end up with a file holding a dozen identical blocks - and, worse, would
     * comment out its own previous line each time. The markers are what make the block replaceable.
     */
    public function testRunningItAgainReplacesItsBlockInsteadOfStackingOne(): void
    {
        $conf = $this->chronyConf();

        $this->runScriptOn($conf);
        $once = (string) file_get_contents($conf);

        $this->runScriptOn($conf);
        $this->runScriptOn($conf);

        self::assertSame($once, (string) file_get_contents($conf));
        self::assertSame(1, substr_count($once, 'server 10.30.20.254 iburst prefer'));
    }

    /** A gateway is what goes into a command line: it is checked before it gets there. */
    public function testAnAddressThatIsNotOneIsRefusedBeforeAnythingIsSent(): void
    {
        $shell = new RecordingShell();

        $this->expectException(\InvalidArgumentException::class);

        try {
            (new GuestTimeSync())->configure($shell, 'ntp.example.org; rm -rf /');
        } finally {
            self::assertSame([], $shell->commands, 'nothing may be sent for an address that is not one');
        }
    }

    /**
     * A template with no chrony answers exit 3 and says so. The caller decides how loud that is -
     * what must never happen is the step passing quietly on a machine whose clock nothing sets.
     */
    public function testAMachineThatRefusesTheStepRaisesRatherThanPassingQuietly(): void
    {
        $shell = new RecordingShell(failingNeedle: 'chrony');

        $this->expectException(GuestCommandFailedException::class);

        (new GuestTimeSync())->configure($shell, '10.30.20.254');
    }

    /** A chrony.conf as a cloud image ships one. */
    private function chronyConf(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'chrony');
        self::assertIsString($path);

        file_put_contents($path, <<<'CONF'
            pool 2.debian.pool.ntp.org iburst
            server ntp.example.org iburst
            driftfile /var/lib/chrony/chrony.drift
            makestep 1 3

            CONF);

        return $path;
    }

    /**
     * The service's own command, aimed at a file in this container rather than at /etc, and without
     * the two lines that need systemd. Everything that edits the file is the real thing.
     */
    private function runScriptOn(string $conf): void
    {
        $shell = new RecordingShell();
        (new GuestTimeSync())->configure($shell, '10.30.20.254');

        $script = $shell->commands[0];
        $script = str_replace('/etc/chrony/chrony.conf /etc/chrony.conf', escapeshellarg($conf), $script);
        $script = preg_replace('/^systemctl .*$/m', 'true', $script) ?? '';
        $script = preg_replace('/^chronyc .*$/m', 'true', $script) ?? '';

        exec(\sprintf('/bin/sh -c %s 2>&1', escapeshellarg($script)), $output, $status);

        self::assertSame(0, $status, implode("\n", $output));
    }
}
