<?php

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

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDisplayName(): string
    {
        return $this->title ?? $this->topic?->getName() ?? '—';
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
