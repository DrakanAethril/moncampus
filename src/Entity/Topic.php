<?php

namespace App\Entity;

use App\Repository\TopicRepository;
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

    #[ORM\Column(name: 'target_cm_hours')]
    #[Assert\PositiveOrZero]
    private int $targetCmHours = 0;

    #[ORM\Column(name: 'target_td_hours')]
    #[Assert\PositiveOrZero]
    private int $targetTdHours = 0;

    #[ORM\Column(name: 'target_tp_hours')]
    #[Assert\PositiveOrZero]
    private int $targetTpHours = 0;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'teacher_id', nullable: true)]
    private ?User $teacher = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'inactive_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inactiveDate = null;

    public function __construct(string $name, Program $program, ?TopicGroup $topicGroup = null)
    {
        $this->name = $name;
        $this->creationDate = new \DateTimeImmutable();
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

    public function getTargetCmHours(): int
    {
        return $this->targetCmHours;
    }

    public function setTargetCmHours(int $targetCmHours): static
    {
        $this->targetCmHours = $targetCmHours;

        return $this;
    }

    public function getTargetTdHours(): int
    {
        return $this->targetTdHours;
    }

    public function setTargetTdHours(int $targetTdHours): static
    {
        $this->targetTdHours = $targetTdHours;

        return $this;
    }

    public function getTargetTpHours(): int
    {
        return $this->targetTpHours;
    }

    public function setTargetTpHours(int $targetTpHours): static
    {
        $this->targetTpHours = $targetTpHours;

        return $this;
    }

    public function getTotalTargetHours(): int
    {
        return $this->targetCmHours + $this->targetTdHours + $this->targetTpHours;
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
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
