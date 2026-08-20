<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\VmBatchItemStatus;
use App\Repository\VmBatchItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One machine of a batch, and the person it is for.
 *
 * **A batch is not atomic**, and this row is why: twenty-four machines are twenty-four independent
 * creations, and one refusal from the hypervisor must not undo the twenty-three that worked. Each
 * item carries its own status and its own message, which is what makes "resume" mean something -
 * it retries the `planned` and `failed` ones and leaves the rest alone.
 */
#[ORM\Entity(repositoryClass: VmBatchItemRepository::class)]
#[ORM\Table(name: 'vm_batch_item')]
#[ORM\Index(name: 'idx_vm_batch_item_batch', columns: ['batch_id'])]
class VmBatchItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: VmBatch::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'batch_id', nullable: false, onDelete: 'CASCADE')]
    private ?VmBatch $batch = null;

    /** The student this machine is for. NULL once their account is gone; the row stays readable. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $student = null;

    #[ORM\Column(name: 'student_label', length: 180)]
    private string $studentLabel;

    #[ORM\Column(name: 'guest_name', length: 64)]
    private string $guestName;

    /**
     * The Unix login the student gets on their own machine.
     *
     * On a PerGroup batch there is no single one - the machine carries one account per member, held
     * by $groupMembers below - and this holds the group's slug instead, which is what the name
     * pattern's `{login}` renders to. Nothing ever creates an account from it in that shape.
     */
    #[ORM\Column(length: 32)]
    private string $login;

    /**
     * On a PerGroup batch, who shares this machine and under which login - a frozen snapshot taken
     * when the batch was planned, the same way $studentLabel is one.
     *
     * A snapshot rather than a join table on purpose: the set of groups it came from is itself a
     * snapshot (App\Entity\GroupBatch), the logins are computed from names that may change, and a
     * plan somebody read on screen must still describe the machines that were built. `userId` is
     * only used to link the account back to a live account when there still is one.
     *
     * Empty on a PerStudent batch, where $student and $login already say everything.
     *
     * @var list<array{userId: int, label: string, login: string}>
     */
    #[ORM\Column(name: 'group_members', type: Types::JSON)]
    private array $groupMembers = [];

    #[ORM\Column(nullable: true)]
    private ?int $vmid = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $node = null;

    #[ORM\ManyToOne(targetEntity: IpAllocation::class)]
    #[ORM\JoinColumn(name: 'ip_allocation_id', nullable: true, onDelete: 'SET NULL')]
    private ?IpAllocation $ipAllocation = null;

    #[ORM\ManyToOne(targetEntity: ProxmoxOperation::class)]
    #[ORM\JoinColumn(name: 'operation_id', nullable: true, onDelete: 'SET NULL')]
    private ?ProxmoxOperation $operation = null;

    #[ORM\Column(length: 20, enumType: VmBatchItemStatus::class)]
    private VmBatchItemStatus $status = VmBatchItemStatus::Planned;

    /**
     * When a pass last took this item in hand - whether or not it moved.
     *
     * It exists to make the queue fair rather than to measure anything: a pass takes the items that
     * have gone longest without a turn, so five machines waiting on a boot cannot keep the sixth
     * from ever starting. Null means never attempted, and MySQL sorts nulls first in ASC, which is
     * the order wanted - a machine that has not begun goes before one that is merely slow.
     */
    #[ORM\Column(name: 'last_attempt_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastAttemptAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    /** Deployment order, which is also the order of the `{index}` in the name pattern. */
    #[ORM\Column]
    private int $position = 0;

    /** @param list<array{userId: int, label: string, login: string}> $groupMembers */
    public function __construct(VmBatch $batch, string $studentLabel, string $guestName, string $login, int $position, array $groupMembers = [])
    {
        $this->batch = $batch;
        $this->studentLabel = $studentLabel;
        $this->guestName = $guestName;
        $this->login = $login;
        $this->position = $position;
        $this->groupMembers = $groupMembers;
    }

    /** @return list<array{userId: int, label: string, login: string}> */
    public function getGroupMembers(): array
    {
        return $this->groupMembers;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBatch(): ?VmBatch
    {
        return $this->batch;
    }

    public function getStudent(): ?User
    {
        return $this->student;
    }

    public function setStudent(?User $student): static
    {
        $this->student = $student;

        return $this;
    }

    public function getStudentLabel(): string
    {
        return $this->studentLabel;
    }

    public function getGuestName(): string
    {
        return $this->guestName;
    }

    public function setGuestName(string $guestName): static
    {
        $this->guestName = $guestName;

        return $this;
    }

    public function getLogin(): string
    {
        return $this->login;
    }

    public function getVmid(): ?int
    {
        return $this->vmid;
    }

    public function setVmid(?int $vmid): static
    {
        $this->vmid = $vmid;

        return $this;
    }

    public function getNode(): ?string
    {
        return $this->node;
    }

    public function setNode(?string $node): static
    {
        $this->node = $node;

        return $this;
    }

    public function getIpAllocation(): ?IpAllocation
    {
        return $this->ipAllocation;
    }

    public function setIpAllocation(?IpAllocation $ipAllocation): static
    {
        $this->ipAllocation = $ipAllocation;

        return $this;
    }

    public function getOperation(): ?ProxmoxOperation
    {
        return $this->operation;
    }

    public function setOperation(?ProxmoxOperation $operation): static
    {
        $this->operation = $operation;

        return $this;
    }

    public function getStatus(): VmBatchItemStatus
    {
        return $this->status;
    }

    public function setStatus(VmBatchItemStatus $status, ?string $message = null): static
    {
        $this->status = $status;
        $this->message = $message;

        return $this;
    }

    public function getLastAttemptAt(): ?\DateTimeImmutable
    {
        return $this->lastAttemptAt;
    }

    /** Stamped by the executor at the start of every attempt - see App\Service\VmBatch\VmBatchExecutor. */
    public function markAttempted(): static
    {
        $this->lastAttemptAt = new \DateTimeImmutable();

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getPosition(): int
    {
        return $this->position;
    }
}
