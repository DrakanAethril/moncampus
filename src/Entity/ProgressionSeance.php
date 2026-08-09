<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EvaluationNature;
use App\Enum\ProgressionSeanceStatus;
use App\Repository\ProgressionSeanceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of screen 2a: a séance of a séquence, as planned inside THIS progression.
 *
 * Distinct from the SeanceInstance it points at: the instance is the frozen pedagogical content
 * (shared by every progression that ever uses that séquence), while this row carries what is
 * specific to this class's planning - where it lands on the timetable, whether it was split,
 * duplicated per group, or dropped. $seanceInstance is nullable for the design's "séance ajoutée"
 * case ("créée pour cette classe uniquement"), which has no library origin at all.
 */
#[ORM\Entity(repositoryClass: ProgressionSeanceRepository::class)]
#[ORM\Table(name: 'progression_seance')]
class ProgressionSeance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ProgressionSequence::class, inversedBy: 'seances')]
    #[ORM\JoinColumn(name: 'progression_sequence_id', nullable: false, onDelete: 'CASCADE')]
    private ?ProgressionSequence $progressionSequence = null;

    #[ORM\ManyToOne(targetEntity: SeanceInstance::class)]
    #[ORM\JoinColumn(name: 'seance_instance_id', nullable: true, onDelete: 'SET NULL')]
    private ?SeanceInstance $seanceInstance = null;

    // Copied from the SeanceInstance at build time (or typed by the teacher for an added séance),
    // then independent - renaming a séance inside one class's progression must not rewrite the
    // instance every other progression reads.
    #[ORM\Column(length: 255)]
    private string $title = '';

    #[ORM\Column]
    private int $position = 0;

    // MINUTES, copied as-is from SeanceInstance::$duree - the library stores séance durations in
    // minutes ("55" is a 55-minute séance, see SeanceTemplateType's field), which the whole
    // progression module now speaks natively rather than converting to decimal hours. The unit is
    // in the column name on purpose: reading a minute count as hours is exactly the bug this
    // replaced (a 55-min séance filled 55 h of a class's year).
    #[ORM\Column(name: 'planned_minutes', nullable: true)]
    private ?int $plannedMinutes = null;

    // "Cette séance contient une évaluation" + its nature, copied from the SeanceInstance at build
    // time (or picked by the teacher for a séance added to this class only) and then independent,
    // exactly like $title and $plannedMinutes above.
    //
    // This is what lets the progression place evaluations WITHOUT anyone posing them by hand: a
    // séance carrying a nature is an evaluation on whatever date its créneau falls, and the
    // calendars/counters read it straight from here. It deliberately creates no App\Entity\Evaluation
    // row - posing one in the Carnet de notes stays an explicit act ("+ Poser une évaluation"),
    // same reasoning as validate() never writing a LessonLog.
    #[ORM\Column(name: 'evaluation_nature', length: 20, enumType: EvaluationNature::class, nullable: true)]
    private ?EvaluationNature $evaluationNature = null;

    // §4.9 - the séance is reproduced once per group, each group getting its own créneau. The
    // group notion is App\Entity\Option (the only sub-class split the timetable actually carries,
    // via lesson_session_option); each placement then names its Option.
    #[ORM\Column(name: 'per_group')]
    private bool $perGroup = false;

    // The design's struck-through "Retirée" row: the créneau is freed and the séance stops
    // counting, but the row survives so "Rétablir" can bring it back. Not a soft-delete of the
    // library content - the séance type stays in the séquence either way.
    #[ORM\Column]
    private bool $removed = false;

    // §4.3 - the séance is shorter than the créneau it sits on. Placed anyway, flagged visually.
    #[ORM\Column(name: 'too_short')]
    private bool $tooShort = false;

    /** @var Collection<int, ProgressionSeancePlacement> */
    #[ORM\OneToMany(mappedBy: 'progressionSeance', targetEntity: ProgressionSeancePlacement::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['partIndex' => 'ASC'])]
    private Collection $placements;

    public function __construct(ProgressionSequence $progressionSequence, string $title)
    {
        $this->progressionSequence = $progressionSequence;
        $this->title = $title;
        $this->placements = new ArrayCollection();
        $progressionSequence->addSeance($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProgressionSequence(): ?ProgressionSequence
    {
        return $this->progressionSequence;
    }

    public function getSeanceInstance(): ?SeanceInstance
    {
        return $this->seanceInstance;
    }

    public function setSeanceInstance(?SeanceInstance $seanceInstance): static
    {
        $this->seanceInstance = $seanceInstance;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getPlannedMinutes(): ?int
    {
        return $this->plannedMinutes;
    }

    public function setPlannedMinutes(?int $plannedMinutes): static
    {
        $this->plannedMinutes = $plannedMinutes;

        return $this;
    }

    // The placement rules need a number to compare, not "unknown" - a séance with no duration set
    // in the library is laid on a créneau as if it filled it, which is what 0 means downstream.
    public function getPlannedMinutesOrZero(): int
    {
        return $this->plannedMinutes ?? 0;
    }

    public function getEvaluationNature(): ?EvaluationNature
    {
        return $this->evaluationNature;
    }

    public function setEvaluationNature(?EvaluationNature $evaluationNature): static
    {
        $this->evaluationNature = $evaluationNature;

        return $this;
    }

    public function hasEvaluation(): bool
    {
        return null !== $this->evaluationNature;
    }

    public function isPerGroup(): bool
    {
        return $this->perGroup;
    }

    public function setPerGroup(bool $perGroup): static
    {
        $this->perGroup = $perGroup;

        return $this;
    }

    public function isRemoved(): bool
    {
        return $this->removed;
    }

    public function setRemoved(bool $removed): static
    {
        $this->removed = $removed;

        return $this;
    }

    public function isTooShort(): bool
    {
        return $this->tooShort;
    }

    public function setTooShort(bool $tooShort): static
    {
        $this->tooShort = $tooShort;

        return $this;
    }

    /** @return Collection<int, ProgressionSeancePlacement> */
    public function getPlacements(): Collection
    {
        return $this->placements;
    }

    public function addPlacement(ProgressionSeancePlacement $placement): static
    {
        if (!$this->placements->contains($placement)) {
            $this->placements->add($placement);
        }

        return $this;
    }

    public function clearPlacements(): static
    {
        $this->placements->clear();

        return $this;
    }

    /** @return list<ProgressionSeancePlacement> placements still pointing at a live créneau */
    public function getActivePlacements(): array
    {
        return array_values(array_filter(
            $this->placements->toArray(),
            static fn (ProgressionSeancePlacement $placement): bool => null !== $placement->getLessonSession(),
        ));
    }

    // §4.7 - true as soon as any créneau this séance sits on was deleted or moved in the
    // timetable. Derived from each placement's own snapshot rather than stored, so a timetable
    // edit made anywhere in the app (staff screen, import, API) is caught without this module
    // having to hook into it.
    public function needsReassociation(): bool
    {
        foreach ($this->placements as $placement) {
            if ($placement->hasDrifted()) {
                return true;
            }
        }

        return false;
    }

    public function getPlacedMinutes(): int
    {
        $total = 0;
        foreach ($this->getActivePlacements() as $placement) {
            $total += $placement->getDurationMinutes();
        }

        return $total;
    }

    public function getStatus(): ProgressionSeanceStatus
    {
        if ($this->removed) {
            return ProgressionSeanceStatus::Removed;
        }

        if ($this->needsReassociation()) {
            return ProgressionSeanceStatus::ToReassociate;
        }

        $placements = $this->getActivePlacements();
        if ([] === $placements) {
            return ProgressionSeanceStatus::NotPlaced;
        }

        $allConfirmed = true;
        foreach ($placements as $placement) {
            $allConfirmed = $allConfirmed && $placement->isConfirmed();
        }

        if (!$allConfirmed) {
            return ProgressionSeanceStatus::ToConfirm;
        }

        return match (true) {
            $this->perGroup => ProgressionSeanceStatus::PerGroup,
            \count($placements) > 1 => ProgressionSeanceStatus::Split,
            default => ProgressionSeanceStatus::Associated,
        };
    }
}
