<?php

declare(strict_types=1);

namespace App\Service\Console;

/**
 * There will be no console on this machine, and trying again will not change that.
 *
 * The message is a translation key, because it is shown to somebody who asked for a terminal and
 * has no use for apt's own words. The one case that reaches it in practice is a machine with no
 * outbound network: tmux is missing and cannot be fetched. The screen then names the degraded
 * command mode rather than an error.
 */
class ConsoleUnavailableException extends \RuntimeException
{
}
