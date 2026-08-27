<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GameAliasRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The name one student carries in one formation, and the three that were offered to them
 * (§4, decision 8).
 *
 * One per formation rather than one per period: the monthly and yearly rankings share the same
 * board, and a pseudonym that changed between them would make the year unreadable.
 *
 * The unique constraint on `(program, figure)` is what guarantees the patronym is unique in the
 * class - **not an application check**. Two students landing on the same figure is refused by the
 * database, which is the only place a race between two simultaneous choices can be settled.
 *
 * $figure stays null while nothing has been chosen. At J+7 the first of the three is attributed and
 * the student is told - a period that starts with no name is a ranking nobody can read.
 */
#[ORM\Entity(repositoryClass: GameAliasRepository::class)]
#[ORM\Table(name: 'game_alias')]
#[ORM\UniqueConstraint(name: 'uniq_game_alias_student', columns: ['student_id', 'program_id'])]
#[ORM\UniqueConstraint(name: 'uniq_game_alias_figure', columns: ['program_id', 'figure_id'])]
class GameAlias
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

    #[ORM\ManyToOne(targetEntity: GameFigure::class)]
    #[ORM\JoinColumn(name: 'figure_id', nullable: true, onDelete: 'SET NULL')]
    private ?GameFigure $figure = null;

    /** The three drawn for this student, in the order they were offered. */
    #[ORM\Column(name: 'offered_figures')]
    private array $offeredFigures = [];

    #[ORM\Column(name: 'offered_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $offeredAt;

    #[ORM\Column(name: 'chosen_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $chosenAt = null;

    /** True when J+7 attributed the first of the three rather than the student choosing. */
    #[ORM\Column(name: 'attributed_by_default')]
    private bool $attributedByDefault = false;

    /**
     * @param list<int> $offeredFigures
     */
    public function __construct(User $student, Program $program, array $offeredFigures)
    {
        $this->student = $student;
        $this->program = $program;
        $this->offeredFigures = $offeredFigures;
        $this->offeredAt = new \DateTimeImmutable();
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

    public function getFigure(): ?GameFigure
    {
        return $this->figure;
    }

    public function choose(GameFigure $figure, bool $byDefault = false, ?\DateTimeImmutable $at = null): static
    {
        $this->figure = $figure;
        $this->chosenAt = $at ?? new \DateTimeImmutable();
        $this->attributedByDefault = $byDefault;

        return $this;
    }

    /** @return list<int> */
    public function getOfferedFigures(): array
    {
        return array_map(intval(...), $this->offeredFigures);
    }

    /**
     * @param array<array-key, int> $offeredFigures
     *
     * The `array_values()` below is input normalisation on a public setter feeding a JSON column,
     * not a redundant call: the parameter is widened rather than the call dropped, exactly as the
     * repository's own rule on `arrayValues.list` says.
     */
    public function setOfferedFigures(array $offeredFigures): static
    {
        $this->offeredFigures = array_values($offeredFigures);

        return $this;
    }

    public function getOfferedAt(): \DateTimeImmutable
    {
        return $this->offeredAt;
    }

    public function getChosenAt(): ?\DateTimeImmutable
    {
        return $this->chosenAt;
    }

    public function isChosen(): bool
    {
        return null !== $this->figure;
    }

    public function isAttributedByDefault(): bool
    {
        return $this->attributedByDefault;
    }

    /** When the choice lapses and the first of the three is attributed. */
    public function deadline(int $days): \DateTimeImmutable
    {
        return $this->offeredAt->modify('+'.$days.' days');
    }
}
