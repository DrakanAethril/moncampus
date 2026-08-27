<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\GameAttendanceStep;
use App\Enum\GameStatementType;
use App\Repository\GameStatementRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A **relevé**: one named document a class fills in, of one of two kinds - an attendance pass or a
 * class council (App\Enum\GameStatementType).
 *
 * **It is deliberately not tied to an App\Entity\EvaluationPeriod.** Until 2026-08-27 there was one
 * attendance statement per week *of a period* and exactly one council *per period*, which meant a
 * team could hold neither four councils in a two-period year nor one across both. A relevé is
 * created by hand, named by hand, and there may be as many or as few as the team wants.
 *
 * The game invents no calendar (§4, decision 2) and no longer borrows the school's either: the
 * points a relevé produces are filed into the **month** its own date falls in
 * (App\Service\Game\GameMonth), which every calendar already has. What changed is that the calendar no
 * longer decides how many documents get filled in - only where their points land.
 *
 * The type decides the fields. An attendance relevé covers a stretch of time and carries a
 * periodicity; a council carries a label and a date, and nothing else.
 */
#[ORM\Entity(repositoryClass: GameStatementRepository::class)]
#[ORM\Table(name: 'game_statement')]
#[ORM\Index(name: 'idx_game_statement_program_type', columns: ['program_id', 'type'])]
class GameStatement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Program $program;

    #[ORM\Column(length: 20, enumType: GameStatementType::class)]
    private GameStatementType $type;

    /** « Semaine du 10 mars », « Conseil du 1er semestre » - free, and typed by a human. */
    #[ORM\Column(length: 160)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 160)]
    private string $name = '';

    /** The day the relevé stands on - what files its points into a period. */
    #[ORM\Column(name: 'held_on', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $heldOn;

    // --- Attendance only -----------------------------------------------------------------------

    #[ORM\Column(name: 'periodicity', length: 10, nullable: true, enumType: GameAttendanceStep::class)]
    private ?GameAttendanceStep $periodicity = null;

    #[ORM\Column(name: 'starts_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startsOn = null;

    #[ORM\Column(name: 'ends_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endsOn = null;

    /** How many weeks one unit is worth - what makes the monthly step arithmetic and not a case. */
    #[ORM\Column(name: 'weeks_covered', nullable: true)]
    #[Assert\Range(min: 1, max: 12)]
    private ?int $weeksCovered = null;

    // -------------------------------------------------------------------------------------------

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * Closed: no longer editable, and for a council the moment its points are inserted.
     *
     * An attendance relevé is closed by hand or by the period closure; a council is closed by the
     * professeur principal, which is when the mentions stop moving.
     */
    #[ORM\Column(name: 'closed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    /** @var Collection<int, GameStatementLine> */
    #[ORM\OneToMany(targetEntity: GameStatementLine::class, mappedBy: 'statement', cascade: ['persist'], orphanRemoval: true)]
    private Collection $lines;

    public function __construct(Program $program, GameStatementType $type, string $name, \DateTimeImmutable $heldOn, ?User $author = null)
    {
        $this->program = $program;
        $this->type = $type;
        $this->name = $name;
        $this->heldOn = $heldOn->setTime(0, 0);
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

    public function getType(): GameStatementType
    {
        return $this->type;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getHeldOn(): \DateTimeImmutable
    {
        return $this->heldOn;
    }

    public function setHeldOn(\DateTimeImmutable $heldOn): static
    {
        $this->heldOn = $heldOn->setTime(0, 0);

        return $this;
    }

    public function getPeriodicity(): ?GameAttendanceStep
    {
        return $this->periodicity;
    }

    public function getStartsOn(): ?\DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function getEndsOn(): ?\DateTimeImmutable
    {
        return $this->endsOn;
    }

    public function getWeeksCovered(): int
    {
        return $this->weeksCovered ?? 1;
    }

    /** The four fields an attendance relevé carries, set together because they only make sense so. */
    public function coverTimeSpan(GameAttendanceStep $periodicity, \DateTimeImmutable $startsOn, \DateTimeImmutable $endsOn, int $weeksCovered): static
    {
        $this->periodicity = $periodicity;
        $this->startsOn = $startsOn->setTime(0, 0);
        $this->endsOn = $endsOn->setTime(0, 0);
        $this->weeksCovered = max(1, $weeksCovered);
        $this->heldOn = $this->endsOn;

        return $this;
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

    /** Re-opening a closed council is an administrator's act, and the points follow by inverse lines. */
    public function reopen(): static
    {
        $this->closedAt = null;

        return $this;
    }

    /** @return Collection<int, GameStatementLine> */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(GameStatementLine $line): static
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
        }

        return $this;
    }

    /** How many students have actually been stated about - « 18 / 30 saisies » on a council. */
    public function statedCount(): int
    {
        return \count(array_filter(
            $this->lines->toArray(),
            static fn (GameStatementLine $line): bool => $line->isStated(),
        ));
    }
}
