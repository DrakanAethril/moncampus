<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TopicRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A curriculum subject planned within one specific Program (e.g. "Algorithmique" for
 * 26-27-SIO1), with its own target CM/TD/TP volumes - ported from the reference app's
 * Topics/TopicsTrainings pair, flattened into one per-Program entity here (no shared/reusable
 * topic list across programs).
 */
#[ORM\Entity(repositoryClass: TopicRepository::class)]
#[ORM\Table(name: 'topic')]
class Topic
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name;

    #[ORM\ManyToOne(targetEntity: Program::class, inversedBy: 'topics')]
    #[ORM\JoinColumn(name: 'program_id', nullable: false)]
    #[Assert\NotNull]
    private ?Program $program = null;

    #[ORM\ManyToOne(targetEntity: TopicGroup::class, inversedBy: 'topics')]
    #[ORM\JoinColumn(name: 'topic_group_id', nullable: false)]
    #[Assert\NotNull]
    private ?TopicGroup $topicGroup = null;

    // Decimal (e.g. 1.5 for 1h30) - string-typed, same DECIMAL convention as
    // LessonSession::$length, to avoid float rounding issues.
    #[ORM\Column(name: 'target_cm_hours', type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    private string $targetCmHours = '0';

    #[ORM\Column(name: 'target_td_hours', type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    private string $targetTdHours = '0';

    #[ORM\Column(name: 'target_tp_hours', type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    private string $targetTpHours = '0';

    // Weight of the matière in the student's overall average (report card) - decimal, not to be
    // confused with Evaluation::$coefficient, which only weights the evaluations *among themselves*
    // inside this matière. Same float type as the latter, so the two levels of weighting are handled
    // the same way - see App\Service\EvaluationAverageCalculator.
    #[ORM\Column]
    #[Assert\GreaterThan(0)]
    private float $coefficient = 1.0;

    /**
     * The titulaires of the matière - several, since a matière can be held by two teachers who
     * each keep their own carnet de notes inside it (see App\Security\Voter\EvaluationVoter,
     * which opens reading to all of them and writing to the author of each evaluation alone).
     *
     * A plain ManyToMany with no position column: where a single name is needed - the Livret
     * alternant's « Formateur » cell, the author of an evaluation born of a travail - it is
     * *derived* from the timetable by App\Service\TopicPrincipalTeacher rather than stored, so
     * there is no second truth to keep in step with the créneaux.
     *
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'topic_teacher')]
    #[ORM\JoinColumn(name: 'topic_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'teacher_id', onDelete: 'CASCADE')]
    private Collection $teachers;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** @var Collection<int, Evaluation> */
    #[ORM\OneToMany(targetEntity: Evaluation::class, mappedBy: 'topic')]
    private Collection $evaluations;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'inactive_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inactiveDate = null;

    public function __construct(string $name, Program $program, ?TopicGroup $topicGroup = null)
    {
        $this->name = $name;
        $this->creationDate = new \DateTimeImmutable();
        $this->evaluations = new ArrayCollection();
        $this->teachers = new ArrayCollection();
        $this->setProgram($program);
        $this->setTopicGroup($topicGroup);
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getProgram(): ?Program
    {
        return $this->program;
    }

    public function setProgram(?Program $program): static
    {
        $this->program = $program;

        // Keep the inverse side in sync in memory - Doctrine only populates it from a fresh
        // query, not automatically from setting the owning side.
        if (null !== $program && !$program->getTopics()->contains($this)) {
            $program->getTopics()->add($this);
        }

        return $this;
    }

    public function getTopicGroup(): ?TopicGroup
    {
        return $this->topicGroup;
    }

    public function setTopicGroup(?TopicGroup $topicGroup): static
    {
        $this->topicGroup = $topicGroup;

        // Keep the inverse side in sync in memory - Doctrine only populates it from a fresh
        // query, not automatically from setting the owning side.
        if (null !== $topicGroup && !$topicGroup->getTopics()->contains($this)) {
            $topicGroup->getTopics()->add($this);
        }

        return $this;
    }

    public function getTargetCmHours(): string
    {
        return $this->targetCmHours;
    }

    public function setTargetCmHours(string $targetCmHours): static
    {
        $this->targetCmHours = $targetCmHours;

        return $this;
    }

    public function getTargetTdHours(): string
    {
        return $this->targetTdHours;
    }

    public function setTargetTdHours(string $targetTdHours): static
    {
        $this->targetTdHours = $targetTdHours;

        return $this;
    }

    public function getTargetTpHours(): string
    {
        return $this->targetTpHours;
    }

    public function setTargetTpHours(string $targetTpHours): static
    {
        $this->targetTpHours = $targetTpHours;

        return $this;
    }

    public function getTotalTargetHours(): string
    {
        return number_format((float) $this->targetCmHours + (float) $this->targetTdHours + (float) $this->targetTpHours, 2, '.', '');
    }

    public function getCoefficient(): float
    {
        return $this->coefficient;
    }

    public function setCoefficient(float $coefficient): static
    {
        $this->coefficient = $coefficient;

        return $this;
    }

    /** @return Collection<int, User> */
    public function getTeachers(): Collection
    {
        return $this->teachers;
    }

    public function addTeacher(?User $teacher): static
    {
        if (null !== $teacher && !$this->teachers->contains($teacher)) {
            $this->teachers->add($teacher);
        }

        return $this;
    }

    public function removeTeacher(User $teacher): static
    {
        $this->teachers->removeElement($teacher);

        return $this;
    }

    public function hasTeacher(User $teacher): bool
    {
        return $this->teachers->contains($teacher);
    }

    public function hasTeachers(): bool
    {
        return !$this->teachers->isEmpty();
    }

    /**
     * The titulaires in display order - alphabetical, so the same matière never names them in two
     * different orders from one screen to the next (a ManyToMany carries no order of its own).
     *
     * @return list<User>
     */
    public function getOrderedTeachers(): array
    {
        $teachers = $this->teachers->toArray();
        usort($teachers, static fn (User $a, User $b): int => strcasecmp(
            $a->getDisplayName() ?? $a->getUsername(),
            $b->getDisplayName() ?? $b->getUsername(),
        ));

        return $teachers;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /** @return Collection<int, Evaluation> */
    public function getEvaluations(): Collection
    {
        return $this->evaluations;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    public function getInactiveDate(): ?\DateTimeImmutable
    {
        return $this->inactiveDate;
    }

    public function setInactiveDate(?\DateTimeImmutable $inactiveDate): static
    {
        $this->inactiveDate = $inactiveDate;

        return $this;
    }
}
