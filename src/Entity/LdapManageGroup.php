<?php

namespace App\Entity;

use App\Repository\LdapManageGroupRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LdapManageGroupRepository::class)]
#[ORM\Table(name: 'ldap_manage_group')]
#[ORM\UniqueConstraint(name: 'uniq_ldap_manage_group_name', columns: ['name'])]
class LdapManageGroup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $description;

    #[ORM\Column(name: 'added_at', type: Types::DATETIME_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $addedAt;

    #[ORM\Column(name: 'added_by', length: 255, options: ['default' => 'direct'])]
    private string $addedBy = 'direct';

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true, 'default' => 0])]
    private int $state = 0;

    #[ORM\Column(type: Types::INTEGER, nullable: true, options: ['unsigned' => true])]
    private ?int $pid = null;

    #[ORM\Column(name: 'started_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'ended_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $log = null;

    public function __construct(string $name, string $description)
    {
        $this->name = $name;
        $this->description = $description;
        $this->addedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getAddedAt(): \DateTimeImmutable
    {
        return $this->addedAt;
    }

    public function getAddedBy(): string
    {
        return $this->addedBy;
    }

    public function setAddedBy(string $addedBy): static
    {
        $this->addedBy = $addedBy;

        return $this;
    }

    public function getState(): int
    {
        return $this->state;
    }

    public function setState(int $state): static
    {
        $this->state = $state;

        return $this;
    }

    public function getPid(): ?int
    {
        return $this->pid;
    }

    public function setPid(?int $pid): static
    {
        $this->pid = $pid;

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function setEndedAt(?\DateTimeImmutable $endedAt): static
    {
        $this->endedAt = $endedAt;

        return $this;
    }

    public function getLog(): ?string
    {
        return $this->log;
    }

    public function setLog(?string $log): static
    {
        $this->log = $log;

        return $this;
    }
}
