<?php

declare(strict_types=1);

namespace App\Tests\Service\Guest;

use App\Service\Guest\GuestCommandResult;
use App\Service\Guest\GuestShell;

/**
 * A GuestShell that records rather than connects. The point of the interface, and the only reason
 * every rule above can be judged without a virtual machine.
 */
class RecordingShell implements GuestShell
{
    /** @var list<string> */
    public array $commands = [];

    /**
     * @param ?string $failingNeedle a command containing this refuses, the way a real machine does:
     *                                a message on the output and a non-zero status
     * @param bool    $undetermined   the refusal comes back with no status at all, as a machine that
     *                                reboots mid-command does
     */
    public function __construct(
        private readonly string $output = '',
        private readonly ?string $failingNeedle = null,
        private readonly bool $undetermined = false,
    ) {
    }

    public function run(string $command): GuestCommandResult
    {
        $this->commands[] = $command;

        if (null !== $this->failingNeedle && str_contains($command, $this->failingNeedle)) {
            return new GuestCommandResult('useradd: cannot open /etc/passwd', $this->undetermined ? null : 1);
        }

        return new GuestCommandResult($this->output, 0);
    }

    public function runAsSelf(string $command): GuestCommandResult
    {
        return $this->run($command);
    }

    public function disconnect(): void
    {
    }
}
