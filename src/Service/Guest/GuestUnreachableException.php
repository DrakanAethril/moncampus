<?php

declare(strict_types=1);

namespace App\Service\Guest;

/**
 * The machine could not be reached, or refused the platform key.
 *
 * Distinct from a command that ran and failed, because they mean different things to the person
 * reading the screen: one is a network or a key problem and affects everything, the other is one
 * command's business.
 */
class GuestUnreachableException extends \RuntimeException
{
    /**
     * The door opened and nothing runs behind it - the shape a cloud image's `disable_root` forced
     * command produces. See App\Service\Guest\GuestShellProbe for what it is and why it fools
     * every check that only asks whether the session opened.
     *
     * Named rather than built inline because `@Symfony`'s `single_line_throw` puts every throw on
     * one line, and three arguments' worth of it does not fit on one worth reading - the same
     * reason App\Service\Proxmox\ProxmoxUnavailableException names its own.
     */
    public static function shellRunsNothing(string $username, string $host, string $answer): self
    {
        return new self(\sprintf('%s@%s accepted the platform key but runs no commands - %s', $username, $host, $answer));
    }
}
