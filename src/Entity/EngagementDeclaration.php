<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EngagementKind;
use App\Enum\EngagementState;
use App\Repository\EngagementDeclarationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Something a student did outside what was asked of them, declared and then looked at (§6).
 *
 * **Useful outside the game: it is a portfolio.** A certification passed, a JPO stood at, a project
 * presented, a classmate tutored - the list of what somebody actually did over two years, with the
 * evidence attached and an adult's signature on each line. That is why it is an entity of its own
 * and not a ledger row, and why it keeps its refusals.
 *
 * Nothing is credited before validation. A refusal carries its reason, is read by the student, and
 * the declaration **stays in the queue struck through** rather than disappearing - which is what
 * stops the same thing being re-filed three times in the hope of a different reviewer.
 */
#[ORM\Entity(repositoryClass: EngagementDeclarationRepository::class)]
#[ORM\Table(name: 'engagement_declaration')]
#[ORM\Index(name: 'idx_engagement_program_period_state', columns: ['program_id', 'period_id', 'state'])]
class EngagementDeclaration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false, onDelete: 'CASCADE')]
    private User $student;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Program $program;

    #[ORM\ManyToOne(targetEntity: EvaluationPeriod::class)]
    #[ORM\JoinColumn(name: 'period_id', nullable: false, onDelete: 'CASCADE')]
    private EvaluationPeriod $period;

    #[ORM\Column(length: 20, enumType: EngagementKind::class)]
    private EngagementKind $kind;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 10, max: 4000)]
    private string $description = '';

    #[ORM\Column(length: 20, enumType: EngagementState::class)]
    private EngagementState $state = EngagementState::Filed;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'reviewer_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $reviewer = null;

    #[ORM\Column(name: 'reviewed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    /** Mandatory on a refusal, and read by the student exactly as it was written. */
    #[ORM\Column(name: 'refusal_reason', type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $refusalReason = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, EngagementDeclarationAttachment> */
    #[ORM\OneToMany(targetEntity: EngagementDeclarationAttachment::class, mappedBy: 'declaration', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $attachments;

    public function __construct(User $student, Program $program, EvaluationPeriod $period, EngagementKind $kind)
    {
        $this->student = $student;
        $this->program = $program;
        $this->period = $period;
        $this->kind = $kind;
        $this->createdAt = new \DateTimeImmutable();
        $this->attachments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStudent(): User
    {
        return $this->student;
    }

    public function getProgram(): Program
    {
        return $this->program;
    }

    public function getPeriod(): EvaluationPeriod
    {
        return $this->period;
    }

    public function getKind(): EngagementKind
    {
        return $this->kind;
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

    public function getState(): EngagementState
    {
        return $this->state;
    }

    public function getReviewer(): ?User
    {
        return $this->reviewer;
    }

    public function getReviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function getRefusalReason(): ?string
    {
        return $this->refusalReason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function validate(User $reviewer, ?\DateTimeImmutable $at = null): static
    {
        $this->state = EngagementState::Validated;
        $this->reviewer = $reviewer;
        $this->reviewedAt = $at ?? new \DateTimeImmutable();
        $this->refusalReason = null;

        return $this;
    }

    public function refuse(User $reviewer, string $reason, ?\DateTimeImmutable $at = null): static
    {
        $this->state = EngagementState::Refused;
        $this->reviewer = $reviewer;
        $this->reviewedAt = $at ?? new \DateTimeImmutable();
        $this->refusalReason = $reason;

        return $this;
    }

    /** @return Collection<int, EngagementDeclarationAttachment> */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function addAttachment(EngagementDeclarationAttachment $attachment): static
    {
        if (!$this->attachments->contains($attachment)) {
            $this->attachments->add($attachment);
        }

        return $this;
    }

    /** What this declaration is worth once validated - the kind's own value, never a free amount. */
    public function points(): int
    {
        return $this->kind->points();
    }
}
