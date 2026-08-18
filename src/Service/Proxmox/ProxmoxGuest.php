<?php

declare(strict_types=1);

namespace App\Service\Proxmox;

/**
 * A machine on a host - QEMU virtual machine or LXC container alike, since the console drives both
 * the same way. Built from one row of `GET /cluster/resources?type=vm`, which is a single call
 * covering every node of the cluster, both guest kinds, and the `template` flag that separates a
 * clonable image from a running machine.
 *
 * Never persisted: Proxmox is the source of truth and the screens read it as they render.
 */
final readonly class ProxmoxGuest
{
    public const string TYPE_QEMU = 'qemu';
    public const string TYPE_LXC = 'lxc';

    public function __construct(
        public int $vmid,
        public string $name,
        public string $node,
        public string $type,
        public string $status,
        public bool $template,
        public ?string $pool,
        public int $maxCpu,
        public float $cpuUsage,
        public int $maxMemoryBytes,
        public int $usedMemoryBytes,
        public int $maxDiskBytes,
        public ?int $uptimeSeconds,
        public ?string $lock,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $read = ProxmoxResponse::of($row);
        $vmid = $read->int('vmid');

        return new self(
            $vmid,
            // A guest can genuinely have no name in Proxmox; showing its VMID beats showing a gap.
            '' !== $read->string('name') ? $read->string('name') : (string) $vmid,
            $read->string('node'),
            self::TYPE_LXC === $read->string('type') ? self::TYPE_LXC : self::TYPE_QEMU,
            $read->string('status', 'unknown'),
            $read->bool('template'),
            $read->nullableString('pool'),
            $read->int('maxcpu'),
            $read->float('cpu'),
            $read->int('maxmem'),
            $read->int('mem'),
            $read->int('maxdisk'),
            $read->nullableInt('uptime'),
            $read->nullableString('lock'),
        );
    }

    public function isRunning(): bool
    {
        return 'running' === $this->status;
    }

    public function isContainer(): bool
    {
        return self::TYPE_LXC === $this->type;
    }

    /** `qemu` or `lxc` - the segment that varies in every /nodes/{node}/{kind}/{vmid} path. */
    public function endpointSegment(): string
    {
        return $this->type;
    }

    public function memoryPercent(): float
    {
        return $this->maxMemoryBytes > 0 ? ($this->usedMemoryBytes / $this->maxMemoryBytes) * 100 : 0.0;
    }

    /**
     * Whether Proxmox is in the middle of something on this guest (`lock=migrate`, `clone`,
     * `backup`…). An action posted onto a locked guest is refused by Proxmox with a message that
     * makes no sense in a flash bar, so the screens grey the buttons out instead.
     */
    public function isLocked(): bool
    {
        return null !== $this->lock && '' !== $this->lock;
    }
}
