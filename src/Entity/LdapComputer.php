<?php

namespace App\Entity;

use App\Repository\LdapComputerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A read-only mirror of an LDAP computer object (a Samba 4 AD DC's built-in CN=Computers
 * container, one entry per machine that's joined the domain) - populated only by
 * App\Service\LdapComputerSyncer's manual "sync now" action (Directory > Computers), never
 * created/edited locally. Rows are only ever added, never updated or removed by a later sync,
 * same "only add the missing ones" contract as LdapUserSyncer/LdapGroupSyncer.
 */
#[ORM\Entity(repositoryClass: LdapComputerRepository::class)]
#[ORM\Table(name: 'ldap_computer')]
#[ORM\UniqueConstraint(name: 'ldap_computer_name_unique', columns: ['name'])]
class LdapComputer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(name: 'dns_host_name', length: 255, nullable: true)]
    private ?string $dnsHostName = null;

    #[ORM\Column(name: 'operating_system', length: 255, nullable: true)]
    private ?string $operatingSystem = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    // Nullable in PHP only so the syncer can set it via setCreatedBy() right after construct,
    // before persist() - same reasoning as AuditableTrait's own $createdBy.
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: false)]
    private ?User $createdBy = null;

    public function __construct(string $name, ?string $dnsHostName, ?string $operatingSystem)
    {
        $this->name = $name;
        $this->dnsHostName = $dnsHostName;
        $this->operatingSystem = $operatingSystem;
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDnsHostName(): ?string
    {
        return $this->dnsHostName;
    }

    public function getOperatingSystem(): ?string
    {
        return $this->operatingSystem;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }
}
