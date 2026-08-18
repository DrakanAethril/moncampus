<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\GuestAccountOrigin;
use App\Enum\GuestAccountState;
use App\Repository\GuestAccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An account MonCampus wants - or knows about - inside one machine.
 *
 * **No password is stored, here or anywhere.** One is generated when the account is created, shown
 * once on a screen made to be printed or read out, and forgotten. That is only tenable because
 * resetting one is a single click: the platform key gets back into the machine without anybody's
 * password. Storing them would mean holding, for every student, a credential to a machine they log
 * into - for the sake of a convenience that a button already provides.
 *
 * The machine is identified by (host, node, vmid) rather than by a relation, for the same reason
 * App\Entity\ProxmoxOperation does: Proxmox is the source of truth and MonCampus stores no machine.
 *
 * `state` is what makes the syncer rejouable, and `Kept` is the case that is easy to leave out:
 * without it, an account somebody deliberately decided not to remove is proposed for removal at
 * every single run, and the screen trains people to ignore it.
 */
#[ORM\Entity(repositoryClass: GuestAccountRepository::class)]
#[ORM\Table(name: 'guest_account')]
#[ORM\UniqueConstraint(name: 'uniq_guest_account_login', columns: ['host_id', 'node', 'vmid', 'login'])]
#[ORM\Index(name: 'idx_guest_account_machine', columns: ['host_id', 'vmid'])]
class GuestAccount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ProxmoxHost::class)]
    #[ORM\JoinColumn(name: 'host_id', nullable: false, onDelete: 'CASCADE')]
    private ?ProxmoxHost $host = null;

    #[ORM\Column(length: 64)]
    private string $node;

    #[ORM\Column]
    private int $vmid;

    /** Unix-normalised: lowercase ASCII, digits, hyphen - see App\Service\Guest\UnixLogin. */
    #[ORM\Column(length: 32)]
    private string $login;

    /** NULL for a fixed account (`prof`, `sae`) that belongs to nobody in particular. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    /** Kept beside the relation so a departed account still reads after the User row is gone. */
    #[ORM\Column(name: 'display_name', length: 180, nullable: true)]
    private ?string $displayName = null;

    #[ORM\Column]
    private bool $sudo = false;

    #[ORM\Column(length: 64)]
    private string $shell = '/bin/bash';

    #[ORM\Column(length: 20, enumType: GuestAccountOrigin::class)]
    private GuestAccountOrigin $origin = GuestAccountOrigin::Member;

    #[ORM\Column(length: 20, enumType: GuestAccountState::class)]
    private GuestAccountState $state = GuestAccountState::ToCreate;

    #[ORM\ManyToOne(targetEntity: VmBatch::class, inversedBy: 'accounts')]
    #[ORM\JoinColumn(name: 'batch_id', nullable: true, onDelete: 'SET NULL')]
    private ?VmBatch $batch = null;

    #[ORM\Column(name: 'synced_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $syncedAt = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    public function __construct(ProxmoxHost $host, string $node, int $vmid, string $login)
    {
        $this->host = $host;
        $this->node = $node;
        $this->vmid = $vmid;
        $this->login = $login;
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHost(): ?ProxmoxHost
    {
        return $this->host;
    }

    public function getNode(): string
    {
        return $this->node;
    }

    public function getVmid(): int
    {
        return $this->vmid;
    }

    public function getLogin(): string
    {
        return $this->login;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        $this->displayName = $user?->getDisplayName() ?? $user?->getUsername();

        return $this;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): static
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function isSudo(): bool
    {
        return $this->sudo;
    }

    public function setSudo(bool $sudo): static
    {
        $this->sudo = $sudo;

        return $this;
    }

    public function getShell(): string
    {
        return $this->shell;
    }

    public function setShell(string $shell): static
    {
        $this->shell = $shell;

        return $this;
    }

    public function getOrigin(): GuestAccountOrigin
    {
        return $this->origin;
    }

    public function setOrigin(GuestAccountOrigin $origin): static
    {
        $this->origin = $origin;

        return $this;
    }

    public function getState(): GuestAccountState
    {
        return $this->state;
    }

    public function setState(GuestAccountState $state): static
    {
        $this->state = $state;

        if (GuestAccountState::Present === $state) {
            $this->syncedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getBatch(): ?VmBatch
    {
        return $this->batch;
    }

    public function setBatch(?VmBatch $batch): static
    {
        $this->batch = $batch;

        return $this;
    }

    public function getSyncedAt(): ?\DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }
}
