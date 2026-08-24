<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LdapManagePasswordRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LdapManagePasswordRepository::class)]
#[ORM\Table(name: 'ldap_manage_password')]
class LdapManagePassword
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    // Snapshot of $user->getUsername() at request time, so the consumer script only needs to
    // read this table (no JOIN back to the app's user table required) - same denormalization
    // convention as LdapManageUser/LdapManageGroup, kept alongside the $user relation on purpose
    // (the relation is for this app's own UI - picker, linking back to the profile - the login
    // column is what the external LDAP-side script actually consumes).
    #[ORM\Column(length: 255)]
    private string $login;

    #[ORM\Column(name: 'added_at', type: Types::DATETIME_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $addedAt;

    #[ORM\Column(name: 'added_by', length: 255, options: ['default' => 'direct'])]
    private string $addedBy = 'direct';

    // Always AES_ENCRYPT(..., AES_KEY), and only ever set on the way in: left NULL at insert time
    // (the consumer script then generates a random password), or pre-filled via
    // LdapManagePasswordRepository::setRequestedPassword() when the user chose one themselves
    // (see ProfileController::changePassword()).
    //
    // It exists solely because the queue is asynchronous - a password the user typed has to
    // survive until the external script picks the row up. The moment that script is done it clears
    // this column back to NULL, so a finished row carries nothing. Nothing in this application
    // reads it: the "Voir" button of Annuaire > Mots de passe was removed on 2026-08-24 along with
    // the decryption path behind it, and Version20260824* wiped the history the old behaviour had
    // accumulated. Treat a non-null value here as a row still in flight, never as something to
    // display.
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

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->login = $user->getUsername();
        $this->addedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getLogin(): string
    {
        return $this->login;
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
