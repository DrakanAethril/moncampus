<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AttendanceState;
use App\Enum\CouncilMention;
use App\Enum\GameStatementType;
use App\Repository\GameStatementLineRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One student's line on one relevé. Which columns it fills depends on the relevé's type, and the
 * two sets are disjoint.
 *
 * **Attendance**: `state`, and nothing else. No motive, no absence date, no counter - the whole
 * point of §4, decision 3, and the reason this table will never grow one: « pas net » says that
 * something happened that week and nothing more.
 *
 * **Council**: `mention` and an optional `comment`. `mention` stays null until somebody has actually
 * said something, which is what makes « 18 / 30 saisies » a real count. The mention is useful well
 * beyond the game - a bulletin, a livret, a student's history - which is why the comment and the
 * signature are kept here rather than being derived and thrown away.
 */
#[ORM\Entity(repositoryClass: GameStatementLineRepository::class)]
#[ORM\Table(name: 'game_statement_line')]
#[ORM\UniqueConstraint(name: 'uniq_game_statement_line', columns: ['statement_id', 'student_id'])]
class GameStatementLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: GameStatement::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'statement_id', nullable: false, onDelete: 'CASCADE')]
    private GameStatement $statement;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false, onDelete: 'CASCADE')]
    private User $student;

    /** Attendance only. Everybody is net until somebody says otherwise - that is what makes the pass a minute's work. */
    #[ORM\Column(length: 20, nullable: true, enumType: AttendanceState::class)]
    private ?AttendanceState $state = null;

    /** Council only, and null until the council has actually said something about this student. */
    #[ORM\Column(length: 20, nullable: true, enumType: CouncilMention::class)]
    private ?CouncilMention $mention = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $comment = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'stated_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $statedBy = null;

    #[ORM\Column(name: 'stated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $statedAt = null;

    public function __construct(GameStatement $statement, User $student)
    {
        $this->statement = $statement;
        $this->student = $student;

        // An attendance line is born complete: the default answer is « net », and a relevé nobody
        // touches is a correct relevé that credits the whole class.
        if (GameStatementType::Attendance === $statement->getType()) {
            $this->state = AttendanceState::Clean;
        }

        $statement->addLine($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatement(): GameStatement
    {
        return $this->statement;
    }

    public function getStudent(): User
    {
        return $this->student;
    }

    public function getState(): AttendanceState
    {
        return $this->state ?? AttendanceState::Clean;
    }

    public function setState(AttendanceState $state, ?User $by = null): static
    {
        $this->state = $state;
        $this->sign($by);

        return $this;
    }

    public function getMention(): ?CouncilMention
    {
        return $this->mention;
    }

    public function setMention(CouncilMention $mention, ?User $by = null): static
    {
        $this->mention = $mention;
        $this->sign($by);

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment, ?User $by = null): static
    {
        $this->comment = null === $comment || '' === trim($comment) ? null : trim($comment);
        $this->sign($by);

        return $this;
    }

    public function getStatedBy(): ?User
    {
        return $this->statedBy;
    }

    public function getStatedAt(): ?\DateTimeImmutable
    {
        return $this->statedAt;
    }

    /**
     * Whether this line says anything at all.
     *
     * An attendance line always does - its default is an answer, not an absence of one. A council
     * line only does once a mention has been placed, which is what « 18 / 30 saisies » counts.
     */
    public function isStated(): bool
    {
        return GameStatementType::Attendance === $this->statement->getType() || null !== $this->mention;
    }

    /** What this line is worth to the game, for a council. Zero on « aucune » and on « avertissement ». */
    public function councilPoints(): int
    {
        return $this->mention?->points() ?? 0;
    }

    private function sign(?User $by): void
    {
        if (null !== $by) {
            $this->statedBy = $by;
        }

        $this->statedAt = new \DateTimeImmutable();
    }
}
