<?php

declare(strict_types=1);

namespace App\Tests\Service\Console;

use App\Service\Console\ConsoleUnavailableException;
use App\Service\Console\GuestPty;
use App\Service\Guest\GuestCommandResult;
use App\Service\Guest\GuestShell;
use PHPUnit\Framework\TestCase;

/**
 * The console's own repair, judged without a machine.
 *
 * This is the behaviour asked for in so many words: when the console cannot be opened, go and look
 * - is tmux there, is it running - do whatever is needed, and then open it. The three cases below
 * are the three a real machine actually presents.
 */
class GuestPtyTest extends TestCase
{
    private const string SCREEN = "0123456789abcdef0123456789abcdef\n0 0 80 24 0\nmoncampus@tp:~$ ";

    /** The ordinary machine: tmux is there, and nothing is installed behind anybody's back. */
    public function testAMachineThatAlreadyHasAConsoleIsNotTouched(): void
    {
        $shell = new ScriptedShell(['tmux' => 'ready', 'default' => self::SCREEN]);

        $pane = (new GuestPty())->open($shell, 164, 42);

        self::assertSame('moncampus@tp:~$ ', $pane->content);
        self::assertSame([], array_filter($shell->elevated, static fn (string $c): bool => str_contains($c, 'apt-get')));
    }

    /**
     * The machine that has never had a console: tmux is missing, so it is installed, and then the
     * console opens. One pass, no campaign on the fleet, no reboot.
     */
    public function testAMachineWithoutTmuxGetsItInstalledAndThenOpens(): void
    {
        $shell = new ScriptedShell(['tmux' => ['missing', 'ready'], 'default' => self::SCREEN]);

        (new GuestPty())->open($shell, 164, 42);

        self::assertNotEmpty(
            array_filter($shell->elevated, static fn (string $c): bool => str_contains($c, 'install -y tmux')),
            'tmux should have been installed, elevated',
        );
    }

    /** A machine with no outbound network: said in those words, not in apt's. */
    public function testAMachineThatCannotBeGivenTmuxSaysSo(): void
    {
        $shell = new ScriptedShell(['tmux' => 'missing', 'default' => self::SCREEN]);

        $this->expectException(ConsoleUnavailableException::class);

        (new GuestPty())->open($shell, 164, 42);
    }

    /**
     * The case that matters most, because it is invisible: the machine was rebooted, so tmux is
     * installed but its server is gone. The exchange comes back unreadable, and the console repairs
     * itself rather than showing a broken screen.
     */
    public function testAnExchangeThatComesBackUnreadableRepairsItselfAndRetries(): void
    {
        $shell = new ScriptedShell([
            'tmux' => 'ready',
            // First exchange: the tmux server is not there, so the pane is nothing at all.
            'default' => ['sh: tmux: no server running on /tmp/tmux-1000/default', self::SCREEN],
        ]);

        $pane = (new GuestPty())->exchange($shell, '6c73', '', 164, 42);

        self::assertSame('moncampus@tp:~$ ', $pane->content);
        self::assertNotEmpty(
            array_filter($shell->plain, static fn (string $c): bool => str_contains($c, 'new-session -A -d')),
            'the session should have been re-opened before the retry',
        );
    }

    /** A machine that stays unreadable is not looped on: one repair, one retry, then the truth. */
    public function testAMachineThatStaysUnreadableIsNotLoopedOn(): void
    {
        $shell = new ScriptedShell(['tmux' => 'ready', 'default' => 'still not a screen']);

        $this->expectException(\App\Service\Console\ConsoleNotReadyException::class);

        (new GuestPty())->exchange($shell, '', '', 164, 42);
    }

    /** The console is moncampus's, never root's: its tmux must not go down the elevated path. */
    public function testTheTerminalItselfIsNeverElevated(): void
    {
        $shell = new ScriptedShell(['tmux' => 'ready', 'default' => self::SCREEN]);

        (new GuestPty())->exchange($shell, '6c73', '', 164, 42);

        self::assertSame([], $shell->elevated, 'nothing about a running console is administrative');
    }
}

/**
 * A GuestShell that answers from a script rather than from a machine, and remembers which of the
 * two doors each command went through - which is the thing under test in the last case.
 */
class ScriptedShell implements GuestShell
{
    /** @var list<string> */
    public array $plain = [];

    /** @var list<string> */
    public array $elevated = [];

    /** @param array<string, string|list<string>> $answers */
    public function __construct(private array $answers)
    {
    }

    public function run(string $command): GuestCommandResult
    {
        $this->elevated[] = $command;

        return new GuestCommandResult($this->answerFor($command), 0);
    }

    public function runAsSelf(string $command): GuestCommandResult
    {
        $this->plain[] = $command;

        return new GuestCommandResult($this->answerFor($command), 0);
    }

    public function disconnect(): void
    {
    }

    private function answerFor(string $command): string
    {
        $key = str_contains($command, 'command -v tmux') ? 'tmux' : 'default';
        $answer = $this->answers[$key] ?? '';

        if (\is_array($answer)) {
            // Successive answers: the machine's state changes as commands are run on it.
            $next = array_shift($answer);
            $this->answers[$key] = [] === $answer ? ($next ?? '') : $answer;

            return $next ?? '';
        }

        return $answer;
    }
}
