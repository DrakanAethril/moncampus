<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConsoleSessionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One console somebody opened on one machine.
 *
 * **A row, not a session.** The terminal itself lives in the machine's `tmux` and survives
 * everything this row does; what is recorded here is *who opened a console on what, when, and as
 * whom* - the trace of a door that can reach a root shell, and the thing that lets the screen say
 * « Anne Dubois est aussi connectée » without asking the machine.
 *
 * Two doors reach it and the columns say which:
 *
 *   - a **teacher**, through the account they hold on the machine (`guestAccount`), judged by
 *     App\Security\Voter\GuestConsoleVoter;
 *   - an **administrator**, through /infrastructure, judged by `access_control`. They hold no
 *     account on the machine, which is exactly why `guestAccount` is nullable.
 *
 * The machine is identified by (host, node, vmid) rather than by a relation, for the same reason
 * App\Entity\ProxmoxOperation and App\Entity\GuestAccount do: Proxmox is the source of truth and
 * MonCampus stores no machine. `ip` is frozen beside it because an address is an allocation and
 * allocations move - what matters afterwards is where the session actually went.
 *
 * A session with no exchange for fifteen minutes is closed by the next pass that notices. Nothing
 * is killed in the machine when that happens: closing a row ends a trace, not a shell.
 */
#[ORM\Entity(repositoryClass: ConsoleSessionRepository::class)]
#[ORM\Table(name: 'console_session')]
#[ORM\Index(name: 'idx_console_session_machine', columns: ['host_id', 'vmid'])]
#[ORM\Index(name: 'idx_console_session_open', columns: ['closed_at'])]
class ConsoleSession
{
    /** After this without a single exchange, a console is somebody who closed their laptop. */
    public const int IDLE_MINUTES = 15;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The teacher's door. NULL on the administrator's, who holds no account on the machine. */
    #[ORM\ManyToOne(targetEntity: GuestAccount::class)]
    #[ORM\JoinColumn(name: 'guest_account_id', nullable: true, onDelete: 'SET NULL')]
    private ?GuestAccount $guestAccount = null;

    #[ORM\ManyToOne(targetEntity: ProxmoxHost::class)]
    #[ORM\JoinColumn(name: 'host_id', nullable: false, onDelete: 'CASCADE')]
    private ?ProxmoxHost $host = null;

    #[ORM\Column(length: 64)]
    private string $node;

    #[ORM\Column]
    private int $vmid;

    /** What the machine is called, frozen: a batch renamed later must not rewrite its own history. */
    #[ORM\Column(name: 'guest_name', length: 128, nullable: true)]
    private ?string $guestName = null;

    #[ORM\Column(length: 45)]
    private string $ip;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'opened_by_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $openedBy = null;

    /** `moncampus`, or the account this session became - see « devenir un étudiant ». */
    #[ORM\Column(name: 'unix_user', length: 32)]
    private string $unixUser;

    #[ORM\Column(name: 'opened_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $openedAt;

    #[ORM\Column(name: 'last_seen_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(name: 'closed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    public function __construct(ProxmoxHost $host, string $node, int $vmid, string $ip, User $openedBy, string $unixUser)
    {
        $this->host = $host;
        $this->node = $node;
        $this->vmid = $vmid;
        $this->ip = $ip;
        $this->openedBy = $openedBy;
        $this->unixUser = $unixUser;
        $this->openedAt = new \DateTimeImmutable();
        $this->lastSeenAt = $this->openedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGuestAccount(): ?GuestAccount
    {
        return $this->guestAccount;
    }

    public function setGuestAccount(?GuestAccount $guestAccount): static
    {
        $this->guestAccount = $guestAccount;

        return $this;
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

    public function getGuestName(): ?string
    {
        return $this->guestName;
    }

    public function setGuestName(?string $guestName): static
    {
        $this->guestName = $guestName;

        return $this;
    }

    public function getIp(): string
    {
        return $this->ip;
    }

    public function setIp(string $ip): static
    {
        $this->ip = $ip;

        return $this;
    }

    public function getOpenedBy(): ?User
    {
        return $this->openedBy;
    }

    public function getUnixUser(): string
    {
        return $this->unixUser;
    }

    public function setUnixUser(string $unixUser): static
    {
        $this->unixUser = $unixUser;

        return $this;
    }

    public function getOpenedAt(): \DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function isOpen(): bool
    {
        return null === $this->closedAt;
    }

    /** Every exchange, so that a console nobody is watching can be told apart from one in use. */
    public function touch(): static
    {
        $this->lastSeenAt = new \DateTimeImmutable();

        return $this;
    }

    /** Idempotent: two tabs closing at once must not make the second one a second closure. */
    public function close(): static
    {
        $this->closedAt ??= new \DateTimeImmutable();

        return $this;
    }

    /** Nobody has typed for a quarter of an hour: this is a laptop that was shut, not a console. */
    public function isIdle(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();

        return $this->lastSeenAt < $now->modify(\sprintf('-%d minutes', self::IDLE_MINUTES));
    }

    /** How long it has been open, in whole minutes - what the resume banner announces. */
    public function openMinutes(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();

        return max(0, (int) floor(($now->getTimestamp() - $this->openedAt->getTimestamp()) / 60));
    }
}
