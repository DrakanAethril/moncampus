<?php

namespace App\Entity;

use App\Repository\TopicGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Groups a Program's own Topics together - every Topic belongs to exactly one TopicGroup
 * (Topic::$topicGroup, required). Same per-Program scoping as Topic itself (no shared/reusable
 * group list across programs), and the same feature gate too (Timetable management - see
 * App\Controller\ProgramTimetableSettingsController), since Topics are timetable-planning data.
 */
#[ORM\Entity(repositoryClass: TopicGroupRepository::class)]
#[ORM\Table(name: 'topic_group')]
class TopicGroup
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

    #[ORM\ManyToOne(targetEntity: Program::class, inversedBy: 'topicGroups')]
    #[ORM\JoinColumn(name: 'program_id', nullable: false)]
    #[Assert\NotNull]
    private ?Program $program = null;

    /** @var Collection<int, Topic> */
    #[ORM\OneToMany(mappedBy: 'topicGroup', targetEntity: Topic::class)]
    private Collection $topics;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'inactive_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inactiveDate = null;

    public function __construct(string $name, Program $program)
    {
        $this->name = $name;
        $this->program = $program;
        $this->topics = new ArrayCollection();
        $this->creationDate = new \DateTimeImmutable();
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

    /** @return Collection<int, Topic> */
    public function getTopics(): Collection
    {
        return $this->topics;
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
