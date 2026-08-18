<?php

declare(strict_types=1);

namespace App\Service\Proxmox;

/**
 * The state of a Proxmox task, from `GET /nodes/{node}/tasks/{upid}/status`.
 *
 * Proxmox answers long operations with a UPID rather than waiting, so every start, stop, clone and
 * creation is really two things: the call that returns an identifier, and the polling that learns
 * how it went. Two fields carry the answer and they must be read in order - `status` says whether
 * it is over (`running` / `stopped`), and only once stopped does `exitstatus` say how: the literal
 * string `OK`, or the error itself.
 */
final readonly class ProxmoxTask
{
    public function __construct(
        public string $upid,
        public string $status,
        public ?string $exitStatus,
        public ?string $node,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row, string $upid): self
    {
        $read = ProxmoxResponse::of($row);

        return new self(
            $upid,
            $read->string('status', 'unknown'),
            $read->nullableString('exitstatus'),
            $read->nullableString('node'),
        );
    }

    public function isFinished(): bool
    {
        return 'stopped' === $this->status;
    }

    public function isSuccess(): bool
    {
        return $this->isFinished() && 'OK' === $this->exitStatus;
    }

    /** The failure as Proxmox worded it, or null while the task is still running. */
    public function failure(): ?string
    {
        if (!$this->isFinished() || $this->isSuccess()) {
            return null;
        }

        return $this->exitStatus ?? 'The task ended without saying how.';
    }
}
