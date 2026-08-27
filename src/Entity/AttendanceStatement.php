<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AttendanceStatementRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One pass of the relevé over one class, covering one whole unit of time - a week by default, a
 * month for a formation that prefers a monthly pass (§4, decision 3).
 *
 * **It is not a register of absences and must never become one.** It asks a single question per
 * student - « aucune absence et aucun retard sur la période ? » - and stores one of three answers.
 * There is no motive, no date, no count anywhere in this table or the next: « pas net » says that
 * something happened that week, and nothing else. That is what makes the pass a minute's work
 * (three or four clicks out of thirty, everybody being net by default) and what means there is no
 * attendance record here to keep, to correct or to protect.
 *
 * $weeksCovered is what makes the monthly step arithmetic rather than a special case: one unit is
 * worth as many weeks as it covers, and the rate normalises the rest.
 *
 * A statement is **closed at the closure of its period** and stops being editable then.
 */
#[ORM\Entity(repositoryClass: AttendanceStatementRepository::class)]
#[ORM\Table(name: 'attendance_statement')]
#[ORM\Index(name: 'idx_attendance_statement_program_period', columns: ['program_id', 'period_id'])]
class AttendanceStatement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Program $program;

    #[ORM\ManyToOne(targetEntity: EvaluationPeriod::class)]
    #[ORM\JoinColumn(name: 'period_id', nullable: false, onDelete: 'CASCADE')]
    private EvaluationPeriod $period;

    #[ORM\Column(name: 'starts_on', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startsOn;

    #[ORM\Column(name: 'ends_on', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $endsOn;

    #[ORM\Column(name: 'weeks_covered')]
    #[Assert\Range(min: 1, max: 12)]
    private int $weeksCovered = 1;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'closed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    /** @var Collection<int, AttendanceStatementLine> */
    #[ORM\OneToMany(targetEntity: AttendanceStatementLine::class, mappedBy: 'statement', cascade: ['persist'], orphanRemoval: true)]
    private Collection $lines;

    public function __construct(Program $program, EvaluationPeriod $period, \DateTimeImmutable $startsOn, \DateTimeImmutable $endsOn, int $weeksCovered, ?User $author = null)
    {
        $this->program = $program;
        $this->period = $period;
        $this->startsOn = $startsOn->setTime(0, 0);
        $this->endsOn = $endsOn->setTime(0, 0);
        $this->weeksCovered = max(1, $weeksCovered);
        $this->author = $author;
        $this->createdAt = new \DateTimeImmutable();
        $this->lines = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProgram(): Program
    {
        return $this->program;
    }

    public function getPeriod(): EvaluationPeriod
    {
        return $this->period;
    }

    public function getStartsOn(): \DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function getEndsOn(): \DateTimeImmutable
    {
        return $this->endsOn;
    }

    public function getWeeksCovered(): int
    {
        return $this->weeksCovered;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function isClosed(): bool
    {
        return null !== $this->closedAt;
    }

    public function close(?\DateTimeImmutable $at = null): static
    {
        $this->closedAt ??= $at ?? new \DateTimeImmutable();

        return $this;
    }

    /** @return Collection<int, AttendanceStatementLine> */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(AttendanceStatementLine $line): static
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
        }

        return $this;
    }
}
