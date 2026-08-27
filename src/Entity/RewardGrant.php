<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RewardGrantRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One reward, actually granted (§5.5).
 *
 * $grantedBy is null when the closure granted it on its own - the four tiers - and carries the
 * teacher's name otherwise, so a student reading their shelf always knows whether a person decided.
 *
 * $usedAt and $usedOn are the consumable's own half: **the student spends it themselves**. A
 * consumable a teacher could refuse would not be a reward, it would be a request - so the screen
 * marks it spent and the teacher is told, they do not grant it.
 *
 * **Nothing here ever moves an index.** A grant is the end of the chain, never a link back into it.
 */
#[ORM\Entity(repositoryClass: RewardGrantRepository::class)]
#[ORM\Table(name: 'reward_grant')]
#[ORM\Index(name: 'idx_reward_grant_student', columns: ['student_id'])]
#[ORM\Index(name: 'idx_reward_grant_program', columns: ['program_id', 'granted_at'])]
class RewardGrant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RewardItem::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private RewardItem $item;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Program $program;

    /** Null on a team or class grant, where $groupRef names the group instead. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: true, onDelete: 'CASCADE')]
    private ?User $student = null;

    /** The team's index inside its App\Entity\GroupBatch - a lot is a frozen list of lists. */
    #[ORM\Column(name: 'group_ref', nullable: true)]
    private ?int $groupRef = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'granted_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $grantedBy = null;

    #[ORM\Column(name: 'granted_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $grantedAt;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(name: 'used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    /** What the consumable was spent on - a work's title, a TP's name. */
    #[ORM\Column(name: 'used_on', length: 255, nullable: true)]
    private ?string $usedOn = null;

    public function __construct(RewardItem $item, Program $program)
    {
        $this->item = $item;
        $this->program = $program;
        $this->grantedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getItem(): RewardItem
    {
        return $this->item;
    }

    public function getProgram(): Program
    {
        return $this->program;
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

    public function getGroupRef(): ?int
    {
        return $this->groupRef;
    }

    public function setGroupRef(?int $groupRef): static
    {
        $this->groupRef = $groupRef;

        return $this;
    }

    public function getGrantedBy(): ?User
    {
        return $this->grantedBy;
    }

    public function setGrantedBy(?User $grantedBy): static
    {
        $this->grantedBy = $grantedBy;

        return $this;
    }

    public function isAutomatic(): bool
    {
        return null === $this->grantedBy;
    }

    public function getGrantedAt(): \DateTimeImmutable
    {
        return $this->grantedAt;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): static
    {
        $this->reason = '' === $reason ? null : $reason;

        return $this;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function getUsedOn(): ?string
    {
        return $this->usedOn;
    }

    public function isUsed(): bool
    {
        return null !== $this->usedAt;
    }

    /** Spent by the student, once. A reward already spent stays on the shelf, marked. */
    public function use(string $usedOn, ?\DateTimeImmutable $at = null): static
    {
        if (null === $this->usedAt) {
            $this->usedAt = $at ?? new \DateTimeImmutable();
            $this->usedOn = '' === $usedOn ? null : $usedOn;
        }

        return $this;
    }
}
