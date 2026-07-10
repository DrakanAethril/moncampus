<?php

namespace App\Entity;

use App\Repository\LdapServiceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A read-only mirror of an LDAP service account (e.g. under a Samba 4 AD DC's OU=Services) -
 * populated only by App\Service\LdapServiceSyncer's manual "sync now" action (Directory >
 * Services), never created/edited locally. Rows are only ever added, never updated or removed by
 * a later sync, same "only add the missing ones" contract as LdapUserSyncer/LdapGroupSyncer.
 */
#[ORM\Entity(repositoryClass: LdapServiceRepository::class)]
#[ORM\Table(name: 'ldap_service')]
#[ORM\UniqueConstraint(name: 'ldap_service_name_unique', columns: ['name'])]
class LdapService
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    // Nullable in PHP only so the syncer can set it via setCreatedBy() right after construct,
    // before persist() - same reasoning as AuditableTrait's own $createdBy.
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: false)]
    private ?User $createdBy = null;

    public function __construct(string $name, ?string $description)
    {
        $this->name = $name;
        $this->description = $description;
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

    public function getDescription(): ?string
    {
        return $this->description;
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
