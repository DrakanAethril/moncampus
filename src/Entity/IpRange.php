<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IpRangeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A named network a teacher can be offered - « Réseau SIO2 Étudiant », 10.30.20.0/24 - and the
 * window inside it that MonCampus is allowed to hand out.
 *
 * **The usable bounds are declared, not derived**, and that is the most useful pair of fields on
 * the form: a /24 holds 254 addresses, but .1 to .49 are the gateway, the switches and everything
 * addressed by hand. Without `firstUsable`/`lastUsable`, the console would eventually offer the
 * gateway to a student's virtual machine.
 *
 * `bridge` and `vlan` are not decoration either. Matching a machine to a range needs **two**
 * criteria - the address falling inside the CIDR *and* the interface being on the right bridge with
 * the right tag - or two ranges both numbered 10.30.x on different VLANs get mixed together, and
 * the registry starts reporting conflicts that do not exist.
 *
 * A range belongs to one host: there is no aggregated multi-host addressing, by design.
 */
#[ORM\Entity(repositoryClass: IpRangeRepository::class)]
#[ORM\Table(name: 'ip_range')]
class IpRange
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** What a teacher reads when choosing a network - the vocabulary of rooms and classes, not of VLANs. */
    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $label;

    #[ORM\ManyToOne(targetEntity: ProxmoxHost::class)]
    #[ORM\JoinColumn(name: 'host_id', nullable: false)]
    private ?ProxmoxHost $host = null;

    #[ORM\Column(length: 43)]
    #[Assert\NotBlank]
    private string $cidr;

    #[ORM\Column(length: 45)]
    #[Assert\NotBlank]
    private string $gateway;

    #[ORM\Column(length: 32)]
    #[Assert\NotBlank]
    private string $bridge = 'vmbr0';

    /** NULL means no tag at all, which is not the same as tag 0 - Proxmox refuses an empty `tag=`. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Assert\Range(min: 1, max: 4094)]
    private ?int $vlan = null;

    #[ORM\Column(name: 'first_usable', length: 45)]
    #[Assert\NotBlank]
    private string $firstUsable;

    #[ORM\Column(name: 'last_usable', length: 45)]
    #[Assert\NotBlank]
    private string $lastUsable;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'last_scan_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastScanAt = null;

    /** @var Collection<int, IpAllocation> */
    #[ORM\OneToMany(targetEntity: IpAllocation::class, mappedBy: 'range')]
    private Collection $allocations;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'inactive_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inactiveDate = null;

    public function __construct(string $label, ProxmoxHost $host, string $cidr, string $gateway, string $firstUsable, string $lastUsable)
    {
        $this->label = $label;
        $this->host = $host;
        $this->cidr = $cidr;
        $this->gateway = $gateway;
        $this->firstUsable = $firstUsable;
        $this->lastUsable = $lastUsable;
        $this->creationDate = new \DateTimeImmutable();
        $this->allocations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getHost(): ?ProxmoxHost
    {
        return $this->host;
    }

    public function setHost(?ProxmoxHost $host): static
    {
        $this->host = $host;

        return $this;
    }

    public function getCidr(): string
    {
        return $this->cidr;
    }

    public function setCidr(string $cidr): static
    {
        $this->cidr = $cidr;

        return $this;
    }

    /** The `/24` of `10.30.20.0/24`, as the number an ipconfig0 string needs. */
    public function getPrefixLength(): int
    {
        $parts = explode('/', $this->cidr);

        return isset($parts[1]) && ctype_digit($parts[1]) ? (int) $parts[1] : 24;
    }

    public function getGateway(): string
    {
        return $this->gateway;
    }

    public function setGateway(string $gateway): static
    {
        $this->gateway = $gateway;

        return $this;
    }

    public function getBridge(): string
    {
        return $this->bridge;
    }

    public function setBridge(string $bridge): static
    {
        $this->bridge = $bridge;

        return $this;
    }

    public function getVlan(): ?int
    {
        return $this->vlan;
    }

    public function setVlan(?int $vlan): static
    {
        $this->vlan = $vlan;

        return $this;
    }

    public function getFirstUsable(): string
    {
        return $this->firstUsable;
    }

    public function setFirstUsable(string $firstUsable): static
    {
        $this->firstUsable = $firstUsable;

        return $this;
    }

    public function getLastUsable(): string
    {
        return $this->lastUsable;
    }

    public function setLastUsable(string $lastUsable): static
    {
        $this->lastUsable = $lastUsable;

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

    public function getLastScanAt(): ?\DateTimeImmutable
    {
        return $this->lastScanAt;
    }

    public function setLastScanAt(?\DateTimeImmutable $lastScanAt): static
    {
        $this->lastScanAt = $lastScanAt;

        return $this;
    }

    /** @return Collection<int, IpAllocation> */
    public function getAllocations(): Collection
    {
        return $this->allocations;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    public function getInactiveDate(): ?\DateTimeImmutable
    {
        return $this->inactiveDate;
    }

    public function setInactiveDate(?\DateTimeImmutable $inactiveDate): static
    {
        $this->inactiveDate = $inactiveDate;

        return $this;
    }

    public function isActive(): bool
    {
        return null === $this->inactiveDate;
    }

    /** `10.30.20.0/24 · vmbr0 · VLAN 20`, the one-line identity every picker shows. */
    public function getSummary(): string
    {
        return \sprintf(
            '%s · %s%s',
            $this->cidr,
            $this->bridge,
            null !== $this->vlan ? ' · VLAN '.$this->vlan : '',
        );
    }
}
