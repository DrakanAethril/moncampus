<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProgressionSeancePlacementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One (séance, créneau) pairing - the join that lets a séance occupy 0..n LessonSessions.
 *
 * Deliberately a table of its own rather than reusing SeanceInstance::$lessonSession (a unique
 * OneToOne): a séance can be split over two créneaux, duplicated once per group, and a créneau can
 * manually carry more than one séance - none of which a unique OneToOne can express. That legacy
 * column is left untouched and keeps backing App\Controller\ProgramSequenceInstanceController.
 *
 * $snapshotDay/$snapshotStartHour/$snapshotEndHour freeze the créneau as it was when the placement
 * was made. Comparing them to the live LessonSession is how §4.7 ("tout changement d'EDT marque
 * les séances concernées à réassocier") is detected - no Doctrine listener on LessonSession, so a
 * timetable edit from anywhere in the app is caught, including a deletion (which nulls the FK).
 */
#[ORM\Entity(repositoryClass: ProgressionSeancePlacementRepository::class)]
#[ORM\Table(name: 'progression_seance_placement')]
class ProgressionSeancePlacement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ProgressionSeance::class, inversedBy: 'placements')]
    #[ORM\JoinColumn(name: 'progression_seance_id', nullable: false, onDelete: 'CASCADE')]
    private ?ProgressionSeance $progressionSeance = null;

    #[ORM\ManyToOne(targetEntity: LessonSession::class)]
    #[ORM\JoinColumn(name: 'lesson_session_id', nullable: true, onDelete: 'SET NULL')]
    private ?LessonSession $lessonSession = null;

    // 0-based. > 0 only for a split séance ("Scindée ½ + ½"), in date order.
    #[ORM\Column(name: 'part_index')]
    private int $partIndex = 0;

    // The group this part is for, when the parent séance is per-group. Null otherwise.
    #[ORM\ManyToOne(targetEntity: Option::class)]
    #[ORM\JoinColumn(name: 'option_id', nullable: true, onDelete: 'SET NULL')]
    private ?Option $option = null;

    // MINUTES actually committed to this créneau - the design's "= créneau" / "1 h" / "1 h 30"
    // duty choice, and what "Ajuster la séance pour ce groupe" writes. Same unit as
    // ProgressionSeance::$plannedMinutes; a créneau's own length (LessonSession::$length, decimal
    // HOURS) is converted on the way in by the placement service, never stored raw.
    #[ORM\Column(name: 'duration_minutes', nullable: true)]
    private ?int $durationMinutes = null;

    // False = the placement service's automatic proposal ("À confirmer"). Set by "Valider le
    // placement", which is also what writes the créneau's title/topic.
    #[ORM\Column]
    private bool $confirmed = false;

    #[ORM\Column(name: 'snapshot_day', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $snapshotDay = null;

    #[ORM\Column(name: 'snapshot_start_hour', type: Types::TIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $snapshotStartHour = null;

    #[ORM\Column(name: 'snapshot_end_hour', type: Types::TIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $snapshotEndHour = null;

    public function __construct(ProgressionSeance $progressionSeance, LessonSession $lessonSession)
    {
        $this->progressionSeance = $progressionSeance;
        $this->lessonSession = $lessonSession;
        $this->captureSnapshot();
        $progressionSeance->addPlacement($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProgressionSeance(): ?ProgressionSeance
    {
        return $this->progressionSeance;
    }

    public function getLessonSession(): ?LessonSession
    {
        return $this->lessonSession;
    }

    public function getPartIndex(): int
    {
        return $this->partIndex;
    }

    public function setPartIndex(int $partIndex): static
    {
        $this->partIndex = $partIndex;

        return $this;
    }

    public function getOption(): ?Option
    {
        return $this->option;
    }

    public function setOption(?Option $option): static
    {
        $this->option = $option;

        return $this;
    }

    public function setDurationMinutes(?int $durationMinutes): static
    {
        $this->durationMinutes = $durationMinutes;

        return $this;
    }

    // Falls back to the créneau's own length, converted from decimal hours - that is what the
    // picker's "= créneau" choice means, and it is stored as null rather than resolved at write
    // time so a créneau later lengthened in the timetable keeps counting for what it now is.
    public function getDurationMinutes(): int
    {
        if (null !== $this->durationMinutes) {
            return $this->durationMinutes;
        }

        return (int) round(60 * (float) ($this->lessonSession?->getLength() ?? '0'));
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed;
    }

    public function setConfirmed(bool $confirmed): static
    {
        $this->confirmed = $confirmed;

        return $this;
    }

    // Re-freezes the créneau as it is now - called on creation and after "Réassocier
    // automatiquement" clears a drift.
    public function captureSnapshot(): static
    {
        $this->snapshotDay = $this->lessonSession?->getDay();
        $this->snapshotStartHour = $this->lessonSession?->getStartHour();
        $this->snapshotEndHour = $this->lessonSession?->getEndHour();

        return $this;
    }

    public function hasDrifted(): bool
    {
        $session = $this->lessonSession;
        if (null === $session) {
            // The créneau was deleted from the timetable - §4.7's most brutal form of "changement
            // d'EDT", and the reason the FK is SET NULL rather than CASCADE (a cascade would erase
            // the evidence instead of surfacing it).
            return true;
        }

        return $session->getDay()?->format('Y-m-d') !== $this->snapshotDay?->format('Y-m-d')
            || $session->getStartHour()?->format('H:i') !== $this->snapshotStartHour?->format('H:i')
            || $session->getEndHour()?->format('H:i') !== $this->snapshotEndHour?->format('H:i');
    }

    // The créneau as it was frozen, for the "à réassocier" message when the live session is gone.
    public function getSnapshotDay(): ?\DateTimeImmutable
    {
        return $this->snapshotDay;
    }

    public function getSnapshotStartHour(): ?\DateTimeImmutable
    {
        return $this->snapshotStartHour;
    }

    public function getSnapshotEndHour(): ?\DateTimeImmutable
    {
        return $this->snapshotEndHour;
    }
}
