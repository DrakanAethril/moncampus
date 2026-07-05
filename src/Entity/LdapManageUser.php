<?php

namespace App\Entity;

use App\Repository\LdapManageUserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LdapManageUserRepository::class)]
#[ORM\Table(name: 'ldap_manage_user')]
class LdapManageUser
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $firstname;

    #[ORM\Column(length: 255)]
    private string $lastname;

    #[ORM\Column(name: 'user_type', length: 255)]
    private string $userType;

    #[ORM\Column(name: 'user_groups', length: 255, options: ['default' => ''])]
    private string $userGroups = '';

    #[ORM\Column(name: 'action_type', length: 255)]
    private string $actionType;

    #[ORM\Column(name: 'added_at', type: Types::DATETIME_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $addedAt;

    #[ORM\Column(name: 'added_by', length: 255, options: ['default' => 'direct'])]
    private string $addedBy = 'direct';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $login = null;

    #[ORM\Column(type: Types::BINARY, length: 255, nullable: true)]
    private mixed $password = null;

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

    public function __construct(string $firstname, string $lastname, string $userType, string $actionType)
    {
        $this->firstname = $firstname;
        $this->lastname = $lastname;
        $this->userType = $userType;
        $this->actionType = $actionType;
        $this->addedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFirstname(): string
    {
        return $this->firstname;
    }

    public function setFirstname(string $firstname): static
    {
        $this->firstname = $firstname;

        return $this;
    }

    public function getLastname(): string
    {
        return $this->lastname;
    }

    public function setLastname(string $lastname): static
    {
        $this->lastname = $lastname;

        return $this;
    }

    public function getUserType(): string
    {
        return $this->userType;
    }

    public function setUserType(string $userType): static
    {
        $this->userType = $userType;

        return $this;
    }

    public function getUserGroups(): string
    {
        return $this->userGroups;
    }

    public function setUserGroups(string $userGroups): static
    {
        $this->userGroups = $userGroups;

        return $this;
    }

    public function getActionType(): string
    {
        return $this->actionType;
    }

    public function setActionType(string $actionType): static
    {
        $this->actionType = $actionType;

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

    public function getLogin(): ?string
    {
        return $this->login;
    }

    public function setLogin(?string $login): static
    {
        $this->login = $login;

        return $this;
    }

    public function getPassword(): mixed
    {
        return $this->password;
    }

    public function setPassword(mixed $password): static
    {
        $this->password = $password;

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
