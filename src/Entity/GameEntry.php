<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\GameFamily;
use App\Enum\GameGestureObject;
use App\Repository\GameEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of the game's ledger - and the ledger is **append only**
 * (design/validated/gamification.md §6).
 *
 * No balance is stored anywhere. A family's points are the sum of its lines, the rate is that sum
 * over what was possible, and the index is recomputed from both. A wrong line is undone by an
 * **inverse line** pointing at it ($reversalOf), never by a delete: a teacher's gesture that was
 * contested and withdrawn has to remain readable by the student it was addressed to, and a journal
 * one can delete from is not a journal.
 *
 * $sourceType / $sourceId name the object that produced the line - a submission, a quiz attempt, an
 * attendance line - as a plain pair rather than a polymorphic Doctrine association: the sources
 * belong to a dozen unrelated tables and none of them should gain a mapping towards the game. That
 * pair is also what makes a re-read idempotent: App\Service\Game\GameLedger refuses a second line
 * on the same (sourceType, sourceId, ruleCode).
 */
#[ORM\Entity(repositoryClass: GameEntryRepository::class)]
#[ORM\Table(name: 'game_entry')]
#[ORM\Index(name: 'idx_game_entry_student_period_family', columns: ['student_id', 'period_id', 'family'])]
#[ORM\Index(name: 'idx_game_entry_program_period', columns: ['program_id', 'period_id'])]
#[ORM\Index(name: 'idx_game_entry_source', columns: ['source_type', 'source_id'])]
class GameEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $student;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Program $program;

    #[ORM\ManyToOne(targetEntity: EvaluationPeriod::class)]
    #[ORM\JoinColumn(name: 'period_id', nullable: false, onDelete: 'CASCADE')]
    private EvaluationPeriod $period;

    #[ORM\Column(length: 20, enumType: GameFamily::class)]
    private GameFamily $family;

    /** The rule that was applied - what the journal prints next to the points ("+30 — TP rendu à l'heure"). */
    #[ORM\Column(name: 'rule_code', length: 60)]
    private string $ruleCode;

    /** Signed: a malus and a reversal are both negative, and neither is a subtraction of a stored balance. */
    #[ORM\Column]
    private int $points;

    #[ORM\Column(name: 'occurred_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(name: 'source_type', length: 60, nullable: true)]
    private ?string $sourceType = null;

    #[ORM\Column(name: 'source_id', nullable: true)]
    private ?int $sourceId = null;

    /** The teacher, on a gesture or a validation; null on everything the machine wrote by itself. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    /** Mandatory on a gesture (§5.4) - read as written by the student it is about. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'reversal_of_id', nullable: true, onDelete: 'CASCADE')]
    private ?self $reversalOf = null;

    /**
     * What a malus is about - dress or behaviour, and nothing else (§4, decision 6).
     *
     * A column rather than a prefix on $reason, because $reason is read by the student exactly as
     * it was typed and must stay the teacher's own sentence; and because a closed list in the
     * schema is what stops the gesture from acquiring a third subject one screen at a time.
     */
    #[ORM\Column(name: 'gesture_object', length: 20, nullable: true, enumType: GameGestureObject::class)]
    private ?GameGestureObject $gestureObject = null;

    #[ORM\Column(name: 'contested_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $contestedAt = null;

    #[ORM\Column(name: 'resolved_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    public function __construct(
        User $student,
        Program $program,
        EvaluationPeriod $period,
        GameFamily $family,
        string $ruleCode,
        int $points,
        ?\DateTimeImmutable $occurredAt = null,
    ) {
        $this->student = $student;
        $this->program = $program;
        $this->period = $period;
        $this->family = $family;
        $this->ruleCode = $ruleCode;
        $this->points = $points;
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable();
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

    public function getFamily(): GameFamily
    {
        return $this->family;
    }

    public function getRuleCode(): string
    {
        return $this->ruleCode;
    }

    public function getPoints(): int
    {
        return $this->points;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getSourceType(): ?string
    {
        return $this->sourceType;
    }

    public function getSourceId(): ?int
    {
        return $this->sourceId;
    }

    public function setSource(?string $sourceType, ?int $sourceId): static
    {
        $this->sourceType = $sourceType;
        $this->sourceId = $sourceId;

        return $this;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): static
    {
        $this->author = $author;

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    public function getGestureObject(): ?GameGestureObject
    {
        return $this->gestureObject;
    }

    public function setGestureObject(?GameGestureObject $gestureObject): static
    {
        $this->gestureObject = $gestureObject;

        return $this;
    }

    public function getReversalOf(): ?self
    {
        return $this->reversalOf;
    }

    public function setReversalOf(?self $reversalOf): static
    {
        $this->reversalOf = $reversalOf;

        return $this;
    }

    public function isReversal(): bool
    {
        return null !== $this->reversalOf;
    }

    public function getContestedAt(): ?\DateTimeImmutable
    {
        return $this->contestedAt;
    }

    public function setContestedAt(?\DateTimeImmutable $contestedAt): static
    {
        $this->contestedAt = $contestedAt;

        return $this;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function setResolvedAt(?\DateTimeImmutable $resolvedAt): static
    {
        $this->resolvedAt = $resolvedAt;

        return $this;
    }

    /** Contested and not yet answered - the state the gestures list and the journal both flag. */
    public function isPendingContest(): bool
    {
        return null !== $this->contestedAt && null === $this->resolvedAt;
    }
}
