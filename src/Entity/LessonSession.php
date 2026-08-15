<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LessonSessionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A single scheduled lesson within a Program's weekly timetable - a calendar event, not a
 * structural/reference entity, so it's hard-deleted (no inactiveDate/audit trail) rather than
 * soft-deactivated like the rest of the structure hierarchy.
 */
#[ORM\Entity(repositoryClass: LessonSessionRepository::class)]
#[ORM\Table(name: 'lesson_session')]
// The table grows with every EDT import and is never purged, and `day` is filtered as a range in
// ten of the repository's queries - Doctrine's automatic foreign-key indexes narrow to one teacher
// or one program, then evaluate the date row by row and sort the result. These two composites turn
// that into a covering range scan: the ORDER BY day, startHour of every timetable screen is the
// index order, so the filesort disappears too. They do not make Doctrine's single-column
// teacher_id/program_id indexes redundant to the schema tool, which keeps both.
//
// A bare index on `day` alone was measured and deliberately left out: every school-wide query
// (findAllForDay, findNext/PreviousSessionDayForAnyProgram) joins Program to filter out inactive
// and test programs, so the optimiser reaches them through idx_lesson_session_program_day and
// never picks a day-only index - not even when forced to lead with this table.
#[ORM\Index(name: 'idx_lesson_session_teacher_day', columns: ['teacher_id', 'day', 'start_hour'])]
#[ORM\Index(name: 'idx_lesson_session_program_day', columns: ['program_id', 'day', 'start_hour'])]
class LessonSession
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $day = null;

    #[ORM\Column(name: 'start_hour', type: Types::TIME_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $startHour = null;

    #[ORM\Column(name: 'end_hour', type: Types::TIME_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $endHour = null;

    // Hours, as a decimal (e.g. 1.5) - manually entered, deliberately never derived from
    // startHour/endHour (those position the session on the timetable; this is the only value
    // ProgramFinancialCalculator uses for FinancialItemSource::Lesson cost calculations).
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\NotNull]
    #[Assert\Positive]
    private ?string $length = null;

    // Optional - a session is expected to have a title OR a topic (enforced by the form, not
    // here), falling back to the topic's own name for display when title is blank.
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $title = null;

    #[ORM\ManyToOne(targetEntity: Program::class, inversedBy: 'lessonSessions')]
    #[ORM\JoinColumn(name: 'program_id', nullable: false)]
    #[Assert\NotNull]
    private ?Program $program = null;

    #[ORM\ManyToOne(targetEntity: Topic::class)]
    #[ORM\JoinColumn(name: 'topic_id', nullable: true)]
    private ?Topic $topic = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'teacher_id', nullable: true)]
    private ?User $teacher = null;

    #[ORM\ManyToOne(targetEntity: Room::class)]
    #[ORM\JoinColumn(name: 'class_room_id', nullable: true)]
    private ?Room $classRoom = null;

    #[ORM\ManyToOne(targetEntity: LessonType::class)]
    #[ORM\JoinColumn(name: 'lesson_type_id', nullable: true)]
    private ?LessonType $lessonType = null;

    /** @var Collection<int, Option> */
    #[ORM\ManyToMany(targetEntity: Option::class)]
    #[ORM\JoinTable(name: 'lesson_session_option')]
    private Collection $options;

    public function __construct(Program $program)
    {
        $this->options = new ArrayCollection();
        $this->setProgram($program);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDay(): ?\DateTimeImmutable
    {
        return $this->day;
    }

    public function setDay(?\DateTimeImmutable $day): static
    {
        $this->day = $day;

        return $this;
    }

    public function getStartHour(): ?\DateTimeImmutable
    {
        return $this->startHour;
    }

    public function setStartHour(?\DateTimeImmutable $startHour): static
    {
        $this->startHour = $startHour;

        return $this;
    }

    public function getEndHour(): ?\DateTimeImmutable
    {
        return $this->endHour;
    }

    public function setEndHour(?\DateTimeImmutable $endHour): static
    {
        $this->endHour = $endHour;

        return $this;
    }

    /**
     * The day and the time together, which the model keeps in two columns. Useful anywhere a séance
     * has to be compared to an instant: scheduled visibility « fin de la séance », deadline
     * « prochaine séance », sorting an activity feed.
     */
    public function getStartAt(): ?\DateTimeImmutable
    {
        return $this->combine($this->startHour);
    }

    public function getEndAt(): ?\DateTimeImmutable
    {
        return $this->combine($this->endHour);
    }

    private function combine(?\DateTimeImmutable $hour): ?\DateTimeImmutable
    {
        if (null === $this->day || null === $hour) {
            return null;
        }

        return $this->day->setTime((int) $hour->format('H'), (int) $hour->format('i'));
    }

    public function getLength(): ?string
    {
        return $this->length;
    }

    public function setLength(?string $length): static
    {
        $this->length = $length;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    // The matière first, the title only failing that: a timetable announces what is taught in it, and
    // a slot with no matière (a meeting, a mock exam) has only its title to give.
    //
    // The opposite held until now, and two modules took advantage of it to copy a séance's name into
    // that title - the old « planifier une séance » screen, then the validation of a progression
    // séquence. Neither writes any more, and two migrations gave their matière back to the slots
    // whose title was still the séance's exact name; what remains are those whose copy has drifted
    // since (séance renamed, séance deleted), which no rule on the data tells apart from a title
    // typed by hand. Hence the display rule, which covers them all.
    public function getDisplayName(): string
    {
        return $this->topic?->getName() ?? $this->title ?? '—';
    }

    public function getTopic(): ?Topic
    {
        return $this->topic;
    }

    public function setTopic(?Topic $topic): static
    {
        $this->topic = $topic;

        return $this;
    }

    public function getProgram(): ?Program
    {
        return $this->program;
    }

    public function setProgram(?Program $program): static
    {
        $this->program = $program;

        // Keep the inverse side in sync in memory - Doctrine only populates it from a fresh
        // query, not automatically from setting the owning side.
        if (null !== $program && !$program->getLessonSessions()->contains($this)) {
            $program->getLessonSessions()->add($this);
        }

        return $this;
    }

    public function getTeacher(): ?User
    {
        return $this->teacher;
    }

    public function setTeacher(?User $teacher): static
    {
        $this->teacher = $teacher;

        return $this;
    }

    public function getClassRoom(): ?Room
    {
        return $this->classRoom;
    }

    public function setClassRoom(?Room $classRoom): static
    {
        $this->classRoom = $classRoom;

        return $this;
    }

    public function getLessonType(): ?LessonType
    {
        return $this->lessonType;
    }

    public function setLessonType(?LessonType $lessonType): static
    {
        $this->lessonType = $lessonType;

        return $this;
    }

    /** @return Collection<int, Option> */
    public function getOptions(): Collection
    {
        return $this->options;
    }

    public function addOption(Option $option): static
    {
        if (!$this->options->contains($option)) {
            $this->options->add($option);
        }

        return $this;
    }

    public function removeOption(Option $option): static
    {
        $this->options->removeElement($option);

        return $this;
    }
}
