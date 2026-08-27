<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CouncilMention;
use App\Repository\ClassCouncilMentionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the class council said about one student for one period - one row, one mention, and no more
 * than one (§6).
 *
 * **Useful well beyond the game**, which is why it is a first-class entity rather than a ledger
 * line: the mention belongs on a bulletin, in a livret, in a student's history, and it stays true
 * whether or not the formation ever plays a game. The game reads it; it does not own it.
 *
 * $lockedAt is the council being closed. Until then everything is corrected in place and nothing is
 * credited; at that moment the mentions stop moving and the points are inserted in one pass. Re-
 * opening a closed council is an administrator's act and is traced by AuditableTrait, and the
 * points follow through inverse lines rather than through a recount (§9).
 */
#[ORM\Entity(repositoryClass: ClassCouncilMentionRepository::class)]
#[ORM\Table(name: 'class_council_mention')]
#[ORM\UniqueConstraint(name: 'uniq_class_council_mention', columns: ['student_id', 'period_id'])]
class ClassCouncilMention
{
    use AuditableTrait;

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

    #[ORM\Column(length: 20, enumType: CouncilMention::class)]
    private CouncilMention $mention = CouncilMention::None;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $comment = null;

    #[ORM\Column(name: 'locked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lockedAt = null;

    public function __construct(User $student, Program $program, EvaluationPeriod $period)
    {
        $this->student = $student;
        $this->program = $program;
        $this->period = $period;
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

    public function getMention(): CouncilMention
    {
        return $this->mention;
    }

    public function setMention(CouncilMention $mention): static
    {
        $this->mention = $mention;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = '' === $comment ? null : $comment;

        return $this;
    }

    public function getLockedAt(): ?\DateTimeImmutable
    {
        return $this->lockedAt;
    }

    public function isLocked(): bool
    {
        return null !== $this->lockedAt;
    }

    public function lock(?\DateTimeImmutable $at = null): static
    {
        $this->lockedAt ??= $at ?? new \DateTimeImmutable();

        return $this;
    }

    /** Re-opening: an administrator's act, and the only way back (§9). */
    public function unlock(): static
    {
        $this->lockedAt = null;

        return $this;
    }
}
