<?php

declare(strict_types=1);

namespace App\Service\Guest;

/**
 * A command ran inside a machine and the machine refused it.
 *
 * Deliberately not a App\Service\Guest\GuestUnreachableException: that one means the machine cannot
 * be reached at all and everything is on hold, which the batch treats as a wait. This one means the
 * machine answered and said no - a real failure, about one command, that nothing further along will
 * fix by trying again.
 *
 * It exists because the alternative was silence. The results of `useradd`, `chpasswd` and the rest
 * were discarded: a refused command left the account uncreated, the batch reported success, and the
 * only trace was a line of output nobody read. The message carries the machine's own words, which
 * name the cause far better than anything this application could say on its behalf.
 */
class GuestCommandFailedException extends \RuntimeException
{
    public static function of(string $command, GuestCommandResult $result): self
    {
        return new self(\sprintf('The machine refused a command (exit %d): %s', $result->exitCode ?? -1, trim($result->output)));
    }
}
