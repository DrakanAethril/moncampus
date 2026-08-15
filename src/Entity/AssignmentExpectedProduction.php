<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AssignmentProductionFormat;
use App\Repository\AssignmentExpectedProductionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An expected production of a Dépôt assignment (design_handoff_creation_travail 2a): what the
 * student must hand in, in which format, and by when.
 *
 * The deadline is optional and departs from the assignment's own, as a LessonLogAttachment's
 * visibility date departs from its section's: null = « the assignment's deadline », a date = the file
 * has its own, and the assignment then shows a reminder banner on the teacher side as on the student
 * side. That is what allows announcing a report for next week and its configuration files for
 * tonight without creating two assignments.
 */
#[ORM\Entity(repositoryClass: AssignmentExpectedProductionRepository::class)]
#[ORM\Table(name: 'assignment_expected_production')]
class AssignmentExpectedProduction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Assignment::class, inversedBy: 'expectedProductions')]
    #[ORM\JoinColumn(name: 'assignment_id', nullable: false, onDelete: 'CASCADE')]
    private ?Assignment $assignment = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';

    #[ORM\Column(length: 20, enumType: AssignmentProductionFormat::class)]
    private AssignmentProductionFormat $format = AssignmentProductionFormat::Any;

    #[ORM\Column(name: 'due_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueDate = null;

    // Display rank: the rows read in the order the teacher laid them out.
    #[ORM\Column]
    private int $position = 0;

    /**
     * The assignment is optional in the constructor: the rows added on the fly in the wizard are born
     * with no carrier (CollectionType instantiates them with no argument) and are attached afterwards
     * by Assignment::addExpectedProduction().
     */
    public function __construct(?Assignment $assignment = null)
    {
        $this->assignment = $assignment;

        if (null !== $assignment && !$assignment->getExpectedProductions()->contains($this)) {
            $assignment->getExpectedProductions()->add($this);
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAssignment(): ?Assignment
    {
        return $this->assignment;
    }

    public function setAssignment(?Assignment $assignment): static
    {
        $this->assignment = $assignment;

        return $this;
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

    public function getFormat(): AssignmentProductionFormat
    {
        return $this->format;
    }

    public function setFormat(AssignmentProductionFormat $format): static
    {
        $this->format = $format;

        return $this;
    }

    public function getDueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function setDueDate(?\DateTimeImmutable $dueDate): static
    {
        $this->dueDate = $dueDate;

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

    /** The deadline that actually applies: its own, otherwise the assignment's. */
    public function getEffectiveDueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate ?? $this->assignment?->getDueDate();
    }

    public function hasOwnDueDate(): bool
    {
        return null !== $this->dueDate;
    }
}
