<?php

declare(strict_types=1);

namespace App\Service\Console;

/**
 * The machine is reachable, but there is no console on it yet.
 *
 * Distinct from App\Service\Guest\GuestUnreachableException on purpose: that one means the door did
 * not open, and there is nothing to do about it from here. This one means the door opened and tmux
 * was missing, dead, or answered something that is not a screen - all of which the console repairs
 * by itself, by installing tmux and opening the session again. It is a step, not a failure.
 */
class ConsoleNotReadyException extends \RuntimeException
{
}
