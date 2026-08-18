<?php

declare(strict_types=1);

namespace App\Service\Guest;

/**
 * What one command inside a machine did.
 *
 * `exitCode` is nullable, and that is not laziness: **a clean disconnection is not a failure**. A
 * script that reboots the machine cuts the session before the exit status can come back, and the
 * honest answer is "it ran, and how it ended is unknown" - which is exactly the state the operation
 * log records as `unknown` and the screen offers to re-check. Reporting a failure there would have
 * administrators chasing a problem that does not exist.
 */
final readonly class GuestCommandResult
{
    public function __construct(
        public string $output,
        public ?int $exitCode,
    ) {
    }

    public function isSuccess(): bool
    {
        return 0 === $this->exitCode;
    }

    /** The session ended before a verdict came back - see the class docblock. */
    public function isUndetermined(): bool
    {
        return null === $this->exitCode;
    }
}
