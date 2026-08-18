<?php

declare(strict_types=1);

namespace App\Service\Guest;

/**
 * Running a command inside a machine.
 *
 * An interface with one method, and it exists for one reason: everything above it - working out
 * which accounts to create, substituting the tokens of a post-installation script, deciding what a
 * non-zero exit code means - is logic worth testing, and none of it should need a virtual machine
 * to be tested. A double replaces this; the real implementation
 * (App\Service\Guest\GuestSshSession) is the only part that needs a network.
 *
 * That separation is also the honest answer to a deployment question the design raises: if the PHP
 * container cannot reach the VM networks, nothing behind this interface works, and everything in
 * front of it still does.
 */
interface GuestShell
{
    /**
     * Runs one command and answers what happened.
     *
     * Never throws on a non-zero exit code: a command that fails has still run, and its output is
     * usually the most useful thing on the screen. Only an unusable connection raises.
     *
     * @throws GuestUnreachableException when the machine cannot be reached or refuses the key
     */
    public function run(string $command): GuestCommandResult;

    /** Closes the connection. Idempotent. */
    public function disconnect(): void;
}
