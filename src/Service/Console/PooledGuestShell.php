<?php

declare(strict_types=1);

namespace App\Service\Console;

use App\Service\Guest\GuestCommandResult;
use App\Service\Guest\GuestShell;

/**
 * A console's SSH session, borrowed from the pool rather than owned.
 *
 * Two things it does, and they are both about the borrowing:
 *
 *   - **`disconnect()` gives the connection back instead of closing it.** Every caller in this
 *     feature closes its shell in a `finally`, which is right when the shell is theirs; with a pool
 *     it would throw away exactly what the pool exists to keep. Nothing else had to change.
 *   - **A connection that has died is reopened once, silently.** A pooled socket the machine has
 *     forgotten answers an empty output with no exit status - the same shape as a command that
 *     never ran - so the reply is indistinguishable from a failure unless something notices. Safe
 *     to replay precisely because a dead connection ran the command zero times.
 */
class PooledGuestShell implements GuestShell
{
    /** @param \Closure(): GuestShell $reopen */
    public function __construct(private GuestShell $shell, private readonly \Closure $reopen)
    {
    }

    public function run(string $command): GuestCommandResult
    {
        return $this->attempt(fn (GuestShell $shell): GuestCommandResult => $shell->run($command));
    }

    public function runAsSelf(string $command): GuestCommandResult
    {
        return $this->attempt(fn (GuestShell $shell): GuestCommandResult => $shell->runAsSelf($command));
    }

    /** Gives the connection back. The pool decides when it is really closed. */
    public function disconnect(): void
    {
    }

    /** @param \Closure(GuestShell): GuestCommandResult $call */
    private function attempt(\Closure $call): GuestCommandResult
    {
        $result = $call($this->shell);

        // "It ran, verdict unknown" - which on a *pooled* connection almost always means the socket
        // was dead before the command left. One reopen, one replay, and no message to anybody:
        // a cache miss must be harmless, and this is what harmless looks like.
        if ('' === $result->output && null === $result->exitCode) {
            $this->shell->disconnect();
            $this->shell = ($this->reopen)();

            return $call($this->shell);
        }

        return $result;
    }
}
