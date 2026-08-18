<?php

declare(strict_types=1);

namespace App\Service\Proxmox;

/**
 * One node of a Proxmox cluster, as `GET /nodes` describes it. Read at display, stored nowhere.
 *
 * Only the fields the console actually shows are declared - the endpoint returns a good deal more
 * (ssl_fingerprint, level, id…) and declaring what we do not read would be a promise about a
 * payload shape nobody checked.
 */
final readonly class ProxmoxNode
{
    public function __construct(
        public string $name,
        public string $status,
        public int $maxCpu,
        public float $cpuUsage,
        public int $maxMemoryBytes,
        public int $usedMemoryBytes,
        public ?int $uptimeSeconds,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $read = ProxmoxResponse::of($row);

        return new self(
            $read->string('node'),
            $read->string('status', 'unknown'),
            $read->int('maxcpu'),
            $read->float('cpu'),
            $read->int('maxmem'),
            $read->int('mem'),
            $read->nullableInt('uptime'),
        );
    }

    public function isOnline(): bool
    {
        return 'online' === $this->status;
    }

    public function memoryPercent(): float
    {
        return $this->maxMemoryBytes > 0 ? ($this->usedMemoryBytes / $this->maxMemoryBytes) * 100 : 0.0;
    }
}
