<?php

namespace App\Entity;

use App\Enum\ProgressionSequenceStatus;
use App\Repository\ProgressionSequenceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One séquence taking its turn in a Progression's year - the rows of screen 5a. $position is the
 * chronological order the placement service walks (design/design_handoff_progression/README.md
 * §4.6/§4.8: "l'ordre = l'enchaînement dans l'année", reordering replans everything after it).
 *
 * Always points at a SequenceInstance, never a SequenceTemplate: a progression plans what is
 * actually being taught to this class this year, and the library template has no dates, no
 * Program and no frozen content (see design/validated/teaching-sequence-library.md).
 */
#[ORM\Entity(repositoryClass: ProgressionSequenceRepository::class)]
#[ORM\Table(name: 'progression_sequence')]
class ProgressionSequence
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Progression::class, inversedBy: 'sequences')]
    #[ORM\JoinColumn(name: 'progression_id', nullable: false, onDelete: 'CASCADE')]
    private ?Progression $progression = null;

    #[ORM\ManyToOne(targetEntity: SequenceInstance::class)]
    #[ORM\JoinColumn(name: 'sequence_instance_id', nullable: false, onDelete: 'CASCADE')]
    private ?SequenceInstance $sequenceInstance = null;

    #[ORM\Column]
    private int $position = 0;

    // The design's "À partir de" - null means "Automatique" (chain on from the previous séquence,
    // §4.6). A set date forces the start and leaves any intermediate créneau free.
    #[ORM\Column(name: 'forced_start_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $forcedStartDate = null;

    #[ORM\Column(name: 'place_in_timetable')]
    private bool $placeInTimetable = true;

    // §4.4 - set by the placement service when the NEXT séquence's forced start date fell before
    // this one had finished, so this one got cut short there. Purely a display flag (the
    // "signalée sur la vue de progression" part of the rule).
    #[ORM\Column(name: 'truncated_by_next')]
    private bool $truncatedByNext = false;

    /** @var Collection<int, ProgressionSeance> */
    #[ORM\OneToMany(mappedBy: 'progressionSequence', targetEntity: ProgressionSeance::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $seances;

    public function __construct(Progression $progression, SequenceInstance $sequenceInstance)
    {
        $this->progression = $progression;
        $this->sequenceInstance = $sequenceInstance;
        $this->seances = new ArrayCollection();
        $progression->addSequence($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProgression(): ?Progression
    {
        return $this->progression;
    }

    public function getSequenceInstance(): ?SequenceInstance
    {
        return $this->sequenceInstance;
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

    public function getForcedStartDate(): ?\DateTimeImmutable
    {
        return $this->forcedStartDate;
    }

    public function setForcedStartDate(?\DateTimeImmutable $forcedStartDate): static
    {
        $this->forcedStartDate = $forcedStartDate;

        return $this;
    }

    public function isPlaceInTimetable(): bool
    {
        return $this->placeInTimetable;
    }

    public function setPlaceInTimetable(bool $placeInTimetable): static
    {
        $this->placeInTimetable = $placeInTimetable;

        return $this;
    }

    public function isTruncatedByNext(): bool
    {
        return $this->truncatedByNext;
    }

    public function setTruncatedByNext(bool $truncatedByNext): static
    {
        $this->truncatedByNext = $truncatedByNext;

        return $this;
    }

    /** @return Collection<int, ProgressionSeance> */
    public function getSeances(): Collection
    {
        return $this->seances;
    }

    public function addSeance(ProgressionSeance $seance): static
    {
        if (!$this->seances->contains($seance)) {
            $this->seances->add($seance);
        }

        return $this;
    }

    public function removeSeance(ProgressionSeance $seance): static
    {
        $this->seances->removeElement($seance);

        return $this;
    }

    public function getTitle(): string
    {
        return $this->sequenceInstance?->getTitre() ?? '—';
    }

    /** @return list<ProgressionSeance> the séances that still count - a removed one is kept only to be restorable */
    public function getActiveSeances(): array
    {
        return array_values(array_filter(
            $this->seances->toArray(),
            static fn (ProgressionSeance $seance): bool => !$seance->isRemoved(),
        ));
    }

    // Minutes, like everything else in this module - see ProgressionSeance::$plannedMinutes.
    public function getPlannedMinutes(): int
    {
        $total = 0;
        foreach ($this->getActiveSeances() as $seance) {
            $total += $seance->getPlannedMinutesOrZero();
        }

        return $total;
    }

    public function getPlacedMinutes(): int
    {
        $total = 0;
        foreach ($this->getActiveSeances() as $seance) {
            $total += $seance->getPlacedMinutes();
        }

        return $total;
    }

    // "02 sept. → 14 oct." on screen 5a - the span actually occupied on the timetable, null while
    // nothing is placed yet ("à partir de janv." then falls back to $forcedStartDate).
    public function getFirstPlacedDay(): ?\DateTimeImmutable
    {
        return $this->boundaryDay(earliest: true);
    }

    public function getLastPlacedDay(): ?\DateTimeImmutable
    {
        return $this->boundaryDay(earliest: false);
    }

    public function getStatus(): ProgressionSequenceStatus
    {
        $seances = $this->getActiveSeances();
        if ([] === $seances) {
            return ProgressionSequenceStatus::NotPlaced;
        }

        $placed = 0;
        foreach ($seances as $seance) {
            if ([] !== $seance->getActivePlacements()) {
                ++$placed;
            }
        }

        return match (true) {
            0 === $placed => ProgressionSequenceStatus::NotPlaced,
            $placed === \count($seances) => ProgressionSequenceStatus::Placed,
            default => ProgressionSequenceStatus::PartiallyPlaced,
        };
    }

    public function countSeancesToPlace(): int
    {
        return \count(array_filter(
            $this->getActiveSeances(),
            static fn (ProgressionSeance $seance): bool => [] === $seance->getActivePlacements(),
        ));
    }

    private function boundaryDay(bool $earliest): ?\DateTimeImmutable
    {
        $found = null;
        foreach ($this->getActiveSeances() as $seance) {
            foreach ($seance->getActivePlacements() as $placement) {
                $day = $placement->getLessonSession()?->getDay();
                if (null === $day) {
                    continue;
                }
                if (null === $found || ($earliest ? $day < $found : $day > $found)) {
                    $found = $day;
                }
            }
        }

        return $found;
    }
}
