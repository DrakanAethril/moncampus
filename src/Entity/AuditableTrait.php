<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Who created/last updated/inactivated a record, and when it was last updated. Mixed into
 * AbstractStructureNode (Section, Track, Cohort, Option, Modality) and into the standalone
 * structure entities (Room, SchoolYear, Program, Period) alike, since Doctrine flattens
 * traits into the class before reading mapping attributes - this works the same whether the
 * using class is a mapped superclass or a plain entity.
 */
trait AuditableTrait
{
    // Nullable in PHP (unlike the DB column) purely so it can be set by the controller via
    // setCreatedBy() right after construction, before persist() - mirrors the pattern used for
    // required relations elsewhere in this hierarchy (e.g. Cohort::$track).
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: false)]
    private ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'inactivated_by_id', nullable: true)]
    private ?User $inactivatedBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'last_updated_by_id', nullable: true)]
    private ?User $lastUpdatedBy = null;

    #[ORM\Column(name: 'last_updated_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUpdatedDate = null;

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getInactivatedBy(): ?User
    {
        return $this->inactivatedBy;
    }

    public function setInactivatedBy(?User $inactivatedBy): static
    {
        $this->inactivatedBy = $inactivatedBy;

        return $this;
    }

    public function getLastUpdatedBy(): ?User
    {
        return $this->lastUpdatedBy;
    }

    public function setLastUpdatedBy(?User $lastUpdatedBy): static
    {
        $this->lastUpdatedBy = $lastUpdatedBy;

        return $this;
    }

    public function getLastUpdatedDate(): ?\DateTimeImmutable
    {
        return $this->lastUpdatedDate;
    }

    public function setLastUpdatedDate(?\DateTimeImmutable $lastUpdatedDate): static
    {
        $this->lastUpdatedDate = $lastUpdatedDate;

        return $this;
    }
}
