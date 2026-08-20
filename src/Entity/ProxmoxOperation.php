<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ProxmoxAction;
use App\Enum\ProxmoxOperationStatus;
use App\Repository\ProxmoxOperationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One thing MonCampus asked a hypervisor to do, and what came back.
 *
 * **The row is written before the call goes out, at `pending`.** That ordering is the whole design:
 * an operation that disappears into a dead network still leaves a trace of who asked for it and
 * when. Writing the row after the answer would lose exactly the cases worth keeping.
 *
 * The machine is described by a *snapshot* - node, vmid, name, type copied at the moment of the
 * act - rather than by a relation, because there is no row to relate to: Proxmox is the source of
 * truth and MonCampus stores no machine. A guest destroyed in Proxmox next week must still leave
 * a readable line here, naming what it was called at the time.
 *
 * `host` and `requestedBy` are `ON DELETE SET NULL` for the same reason: the log outlives both.
 *
 * There is no `delete` action - see App\Enum\ProxmoxAction.
 */
#[ORM\Entity(repositoryClass: ProxmoxOperationRepository::class)]
#[ORM\Table(name: 'proxmox_operation')]
#[ORM\Index(name: 'idx_proxmox_operation_requested', columns: ['requested_at'])]
#[ORM\Index(name: 'idx_proxmox_operation_host', columns: ['host_id'])]
#[ORM\Index(name: 'idx_proxmox_operation_status', columns: ['status'])]
class ProxmoxOperation
{
    /** Enough to read a failure without turning the column into a place scripts get stored. */
    public const int MAX_OUTPUT_BYTES = 65536;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ProxmoxHost::class)]
    #[ORM\JoinColumn(name: 'host_id', nullable: true, onDelete: 'SET NULL')]
    private ?ProxmoxHost $host = null;

    /** Kept next to the relation so a deactivated - or one day deleted - host still reads. */
    #[ORM\Column(name: 'host_label', length: 120)]
    private string $hostLabel;

    #[ORM\Column(length: 20, enumType: ProxmoxAction::class)]
    private ProxmoxAction $action;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $node = null;

    #[ORM\Column(nullable: true)]
    private ?int $vmid = null;

    #[ORM\Column(name: 'guest_name', length: 128, nullable: true)]
    private ?string $guestName = null;

    /** `qemu` or `lxc` - a snapshot too, since the machine may be gone by the time this is read. */
    #[ORM\Column(name: 'guest_type', length: 8, nullable: true)]
    private ?string $guestType = null;

    #[ORM\Column(length: 20, enumType: ProxmoxOperationStatus::class)]
    private ProxmoxOperationStatus $status = ProxmoxOperationStatus::Pending;

    /** The Proxmox task id. Kept even when the outcome is unknown - it is how the answer is found there. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $upid = null;

    /** Proxmox's own wording, untranslated on purpose: a glossary would be one more thing to maintain, and the raw string is what is searchable. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $output = null;

    #[ORM\Column(name: 'exit_code', type: Types::SMALLINT, nullable: true)]
    private ?int $exitCode = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'requested_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $requestedBy = null;

    #[ORM\Column(name: 'requested_by_label', length: 180)]
    private string $requestedByLabel;

    #[ORM\Column(name: 'requested_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(name: 'settled_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $settledAt = null;

    /**
     * Whether somebody is expected to finish this machine once the clone lands - configuration,
     * then the start if it was asked for.
     *
     * Only a creation ever carries it, and only the creation wizard sets it: a batch drives its own
     * machines through App\Service\VmBatch\VmBatchExecutor and must not be configured twice. The
     * wizard has nothing of the sort - it answers a redirect the moment Proxmox accepts the clone -
     * so what finishes the job is whoever is watching the operation. See
     * App\Service\Proxmox\GuestCreationCompleter.
     */
    #[ORM\Column(name: 'completion_requested', options: ['default' => false])]
    private bool $completionRequested = false;

    /** The wizard's « démarrer après la création » box, kept because the start happens much later. */
    #[ORM\Column(name: 'start_after_creation', options: ['default' => false])]
    private bool $startAfterCreation = false;

    /** Set once the machine has been configured, so two pollers at once do it only once. */
    #[ORM\Column(name: 'configured_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $configuredAt = null;

    public function __construct(ProxmoxHost $host, ProxmoxAction $action, ?User $requestedBy)
    {
        $this->host = $host;
        $this->hostLabel = $host->getLabel();
        $this->action = $action;
        $this->requestedBy = $requestedBy;
        $this->requestedByLabel = $requestedBy?->getDisplayName() ?? $requestedBy?->getUsername() ?? '—';
        $this->requestedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHost(): ?ProxmoxHost
    {
        return $this->host;
    }

    public function getHostLabel(): string
    {
        return $this->hostLabel;
    }

    public function getAction(): ProxmoxAction
    {
        return $this->action;
    }

    public function getNode(): ?string
    {
        return $this->node;
    }

    public function getVmid(): ?int
    {
        return $this->vmid;
    }

    public function getGuestName(): ?string
    {
        return $this->guestName;
    }

    public function getGuestType(): ?string
    {
        return $this->guestType;
    }

    /** The four snapshot columns move together or not at all - they describe one machine. */
    public function describeGuest(string $node, int $vmid, ?string $guestName, ?string $guestType): static
    {
        $this->node = $node;
        $this->vmid = $vmid;
        $this->guestName = $guestName;
        $this->guestType = $guestType;

        return $this;
    }

    public function getStatus(): ProxmoxOperationStatus
    {
        return $this->status;
    }

    public function getUpid(): ?string
    {
        return $this->upid;
    }

    /** The wizard, saying it will not be there to finish the job itself. */
    public function requestCompletion(bool $startAfterCreation): static
    {
        $this->completionRequested = true;
        $this->startAfterCreation = $startAfterCreation;

        return $this;
    }

    public function isCompletionRequested(): bool
    {
        return $this->completionRequested;
    }

    public function wantsStartAfterCreation(): bool
    {
        return $this->startAfterCreation;
    }

    public function getConfiguredAt(): ?\DateTimeImmutable
    {
        return $this->configuredAt;
    }

    public function markConfigured(): static
    {
        $this->configuredAt = new \DateTimeImmutable();

        return $this;
    }

    /** Proxmox accepted the request and handed back a task id: the operation is now under way. */
    public function markRunning(string $upid): static
    {
        $this->status = ProxmoxOperationStatus::Running;
        $this->upid = $upid;

        return $this;
    }

    public function markSucceeded(?string $message = null): static
    {
        return $this->settle(ProxmoxOperationStatus::Succeeded, $message);
    }

    public function markFailed(?string $message): static
    {
        return $this->settle(ProxmoxOperationStatus::Failed, $message);
    }

    /**
     * The request went out and the answer never came back. Deliberately its own outcome: claiming
     * either success or failure would be a lie, and the UPID is kept so the truth stays findable
     * in Proxmox.
     */
    public function markUnknown(?string $message): static
    {
        return $this->settle(ProxmoxOperationStatus::Unknown, $message);
    }

    private function settle(ProxmoxOperationStatus $status, ?string $message): static
    {
        $this->status = $status;
        $this->message = $message;
        $this->settledAt = new \DateTimeImmutable();

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getOutput(): ?string
    {
        return $this->output;
    }

    /** Truncated on a line boundary, so a cut script still reads as one. */
    public function setOutput(?string $output): static
    {
        if (null !== $output && \strlen($output) > self::MAX_OUTPUT_BYTES) {
            $cut = substr($output, 0, self::MAX_OUTPUT_BYTES);
            $lastBreak = strrpos($cut, "\n");
            $output = (false !== $lastBreak ? substr($cut, 0, $lastBreak) : $cut)."\n…";
        }

        $this->output = $output;

        return $this;
    }

    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    public function setExitCode(?int $exitCode): static
    {
        $this->exitCode = $exitCode;

        return $this;
    }

    public function getRequestedBy(): ?User
    {
        return $this->requestedBy;
    }

    public function getRequestedByLabel(): string
    {
        return $this->requestedByLabel;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getSettledAt(): ?\DateTimeImmutable
    {
        return $this->settledAt;
    }

    /** How long it took, or how long it has been going, in seconds. */
    public function durationSeconds(): int
    {
        $end = $this->settledAt ?? new \DateTimeImmutable();

        return max(0, $end->getTimestamp() - $this->requestedAt->getTimestamp());
    }
}
