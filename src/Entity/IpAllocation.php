<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\IpAllocationOrigin;
use App\Enum\IpAllocationStatus;
use App\Repository\IpAllocationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One address of one range, and who holds it.
 *
 * **The database guarantees that a live allocation is unique per (range, address), and no PHP check
 * does.** Two administrators launching a batch at the same second would both read "the next free
 * one is .57" and both write it; a `SELECT` followed by an `INSERT` loses that race every time it
 * is run often enough, and address collisions are exactly the failure that takes weeks to trace.
 *
 * MySQL 8 has no partial unique index, so the technique is a `live_key` column that holds the
 * address while the allocation lives and `NULL` once it is released - two NULLs never collide in a
 * unique index, so a released row stops occupying its slot without being deleted. Nothing but
 * `setStatus()` writes it, which is what keeps the two in step.
 *
 * A released row is kept rather than deleted: it is the history of who held what.
 */
#[ORM\Entity(repositoryClass: IpAllocationRepository::class)]
#[ORM\Table(name: 'ip_allocation')]
#[ORM\UniqueConstraint(name: 'uniq_ip_allocation_live', columns: ['range_id', 'live_key'])]
#[ORM\Index(name: 'idx_ip_allocation_range', columns: ['range_id'])]
#[ORM\Index(name: 'idx_ip_allocation_status', columns: ['status'])]
class IpAllocation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: IpRange::class, inversedBy: 'allocations')]
    #[ORM\JoinColumn(name: 'range_id', nullable: false, onDelete: 'CASCADE')]
    private ?IpRange $range = null;

    #[ORM\Column(length: 45)]
    private string $ip;

    /**
     * The address while the allocation lives, NULL once released. The whole uniqueness scheme, and
     * the reason it is never set by hand - see setStatus().
     */
    #[ORM\Column(name: 'live_key', length: 45, nullable: true)]
    private ?string $liveKey = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $hostname = null;

    #[ORM\Column(name: 'mac_address', length: 17, nullable: true)]
    private ?string $macAddress = null;

    #[ORM\Column(nullable: true)]
    private ?int $vmid = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $node = null;

    #[ORM\Column(length: 20, enumType: IpAllocationStatus::class)]
    private IpAllocationStatus $status = IpAllocationStatus::Reserved;

    #[ORM\Column(length: 20, enumType: IpAllocationOrigin::class)]
    private IpAllocationOrigin $origin = IpAllocationOrigin::Declared;

    #[ORM\ManyToOne(targetEntity: ProxmoxOperation::class)]
    #[ORM\JoinColumn(name: 'operation_id', nullable: true, onDelete: 'SET NULL')]
    private ?ProxmoxOperation $operation = null;

    /** What an `external` address is, since nothing else can say: « Imprimante salle B12 ». */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'reserved_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $reservedAt;

    #[ORM\Column(name: 'confirmed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(name: 'released_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $releasedAt = null;

    public function __construct(IpRange $range, string $ip, IpAllocationOrigin $origin = IpAllocationOrigin::Declared)
    {
        $this->range = $range;
        $this->ip = $ip;
        $this->liveKey = $ip;
        $this->origin = $origin;
        $this->reservedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRange(): ?IpRange
    {
        return $this->range;
    }

    public function getIp(): string
    {
        return $this->ip;
    }

    public function getLiveKey(): ?string
    {
        return $this->liveKey;
    }

    public function getHostname(): ?string
    {
        return $this->hostname;
    }

    public function setHostname(?string $hostname): static
    {
        $this->hostname = $hostname;

        return $this;
    }

    public function getMacAddress(): ?string
    {
        return $this->macAddress;
    }

    public function setMacAddress(?string $macAddress): static
    {
        $this->macAddress = null !== $macAddress ? strtoupper($macAddress) : null;

        return $this;
    }

    public function getVmid(): ?int
    {
        return $this->vmid;
    }

    public function setVmid(?int $vmid): static
    {
        $this->vmid = $vmid;

        return $this;
    }

    public function getNode(): ?string
    {
        return $this->node;
    }

    public function setNode(?string $node): static
    {
        $this->node = $node;

        return $this;
    }

    public function getStatus(): IpAllocationStatus
    {
        return $this->status;
    }

    /**
     * The only writer of `live_key`, and the reason the unique index means what it says: releasing
     * an address is what frees its slot, and it frees it here or nowhere.
     */
    public function setStatus(IpAllocationStatus $status): static
    {
        $this->status = $status;
        $this->liveKey = $status->isLive() ? $this->ip : null;

        if (IpAllocationStatus::Confirmed === $status && null === $this->confirmedAt) {
            $this->confirmedAt = new \DateTimeImmutable();
        }

        if (IpAllocationStatus::Released === $status) {
            $this->releasedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getOrigin(): IpAllocationOrigin
    {
        return $this->origin;
    }

    public function setOrigin(IpAllocationOrigin $origin): static
    {
        $this->origin = $origin;

        return $this;
    }

    public function getOperation(): ?ProxmoxOperation
    {
        return $this->operation;
    }

    public function setOperation(?ProxmoxOperation $operation): static
    {
        $this->operation = $operation;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getReservedAt(): \DateTimeImmutable
    {
        return $this->reservedAt;
    }

    public function getConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function getReleasedAt(): ?\DateTimeImmutable
    {
        return $this->releasedAt;
    }

    public function isLive(): bool
    {
        return $this->status->isLive();
    }

    /**
     * A reservation that nothing came of. Thirty minutes is the design's figure: long enough for a
     * wizard somebody walked away from mid-step, short enough that an abandoned batch does not hold
     * two dozen addresses overnight.
     */
    public function isStaleReservation(int $afterSeconds = 1800): bool
    {
        return IpAllocationStatus::Reserved === $this->status
            && null === $this->operation
            && (new \DateTimeImmutable())->getTimestamp() - $this->reservedAt->getTimestamp() > $afterSeconds;
    }
}
