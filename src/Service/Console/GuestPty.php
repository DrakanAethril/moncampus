<?php

declare(strict_types=1);

namespace App\Service\Console;

use App\Service\Guest\GuestShell;
use App\Service\Guest\GuestUnreachableException;

/**
 * The console's protocol, spoken over an ordinary SSH session.
 *
 * Nothing is held here. Every method is one command, sent down a session somebody else opened and
 * will close: the state a terminal needs - the live shell, the current directory, the open `vim` -
 * lives in the machine's `tmux`, which is the whole design.
 *
 * **This class repairs before it fails.** A machine that has never had a console has no `tmux`, and
 * a machine that was rebooted has no tmux *server*; both answer an exchange with something that is
 * not a screen. Rather than showing that to somebody who asked for a terminal, an exchange that
 * comes back unreadable falls into ensure(): check whether tmux is there, install it if it is not,
 * open the session, and try once more. It is a step, not an error - and the screen says
 * « préparation de la console… » while it happens.
 *
 * Only when tmux genuinely cannot be installed - a machine with no outbound network, typically -
 * does this give up, and then it says so in those words rather than in apt's.
 */
class GuestPty
{
    /**
     * How long an exchange may watch the screen inside the machine before answering anyway.
     *
     * Eight seconds: long enough that a console at rest costs about eight SSH handshakes a minute,
     * short enough that no proxy on the way considers the request abandoned.
     */
    public const float WATCH_SECONDS = 8.0;

    /** What a console session's SSH commands may take, against the ordinary five minutes. */
    public const int COMMAND_TIMEOUT_SECONDS = 10;

    /** Installing a package is not an exchange: it takes as long as apt takes. */
    public const int INSTALL_TIMEOUT_SECONDS = 180;

    /**
     * Opens - or rejoins - the console session, and hands back what is on the screen.
     *
     * @throws ConsoleUnavailableException when the machine has no console and cannot be given one
     * @throws GuestUnreachableException   when the machine stopped answering mid-way
     */
    public function open(GuestShell $shell, int $columns, int $rows): ConsolePane
    {
        $this->ensure($shell, $columns, $rows);

        return $this->read($shell, TmuxCommandLine::snapshotOnly($columns, $rows));
    }

    /**
     * One exchange: the bytes typed go in, and the screen comes back as soon as it changes.
     *
     * @param string $hex   what xterm.js produced, hexadecimal; empty when only watching
     * @param string $since the digest of the screen the browser is showing
     *
     * @throws ConsoleUnavailableException
     * @throws GuestUnreachableException
     * @throws \InvalidArgumentException  when the bytes or the digest are not what they claim
     */
    public function exchange(GuestShell $shell, string $hex, string $since, int $columns, int $rows): ConsolePane
    {
        $command = TmuxCommandLine::exchange($hex, $since, $columns, $rows, self::WATCH_SECONDS);

        try {
            return $this->read($shell, $command);
        } catch (ConsoleNotReadyException) {
            // The far side answered something that is not a screen. Repair, then try once - and
            // once only: a second failure is a machine that cannot hold a console, and looping
            // would hold a worker while saying nothing.
            $this->ensure($shell, $columns, $rows);

            return $this->read($shell, $command);
        }
    }

    /** Types a whole command line into the session, exactly as if somebody had typed it. */
    public function sendLine(GuestShell $shell, string $command): void
    {
        $shell->runAsSelf(TmuxCommandLine::sendLine($command));
    }

    /** The scrollback, as text, for searching through what has already gone past. */
    public function history(GuestShell $shell, int $lines): string
    {
        return $shell->runAsSelf(TmuxCommandLine::history($lines))->output;
    }

    /**
     * Impure on purpose, and it has to be declared: the answer is a fact about a machine at one
     * moment, and install() is precisely what changes it between two calls.
     *
     * @phpstan-impure
     */
    public function isPresent(GuestShell $shell): bool
    {
        return str_contains($shell->runAsSelf(TmuxCommandLine::presence())->output, 'ready');
    }

    /**
     * Makes sure there is a console to talk to, installing tmux if that is what is missing.
     *
     * Idempotent and cheap in the ordinary case: on a machine that already has tmux this is one
     * `command -v` and one `new-session -A`, both of which answer instantly.
     *
     * @throws ConsoleUnavailableException when tmux is absent and cannot be installed
     */
    public function ensure(GuestShell $shell, int $columns, int $rows): void
    {
        if (!$this->isPresent($shell)) {
            // Elevated, unlike everything else here: installing a package is administrative, and
            // it is the one thing the console does *as* an administrator rather than as moncampus.
            $shell->run(TmuxCommandLine::install());

            if (!$this->isPresent($shell)) {
                throw new ConsoleUnavailableException('consoleTmuxUninstallableMessage');
            }
        }

        $shell->runAsSelf(TmuxCommandLine::open($columns, $rows));
    }

    /** @throws ConsoleNotReadyException when what came back is not a screen */
    private function read(GuestShell $shell, string $command): ConsolePane
    {
        return ConsolePane::parse($shell->runAsSelf($command)->output);
    }
}
