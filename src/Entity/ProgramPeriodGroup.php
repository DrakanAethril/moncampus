<?php

namespace App\Entity;

use App\Repository\ProgramPeriodGroupRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A Program's attachment to one of its PeriodGroups, with a 1-based priority (1 = most
 * important) deciding which group's Periods win when two attached groups overlap on the same
 * date - see InternshipCalendarBuilder::findPeriodForDate(), which resolves same-day overlaps by
 * "first match wins" and is fed periods ordered by this priority.
 */
#[ORM\Entity(repositoryClass: ProgramPeriodGroupRepository::class)]
#[ORM\Table(name: 'program_period_group')]
#[ORM\UniqueConstraint(name: 'program_period_group_unique', columns: ['program_id', 'period_group_id'])]
class ProgramPeriodGroup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Program::class, inversedBy: 'programPeriodGroups')]
    #[ORM\JoinColumn(name: 'program_id', nullable: false)]
    #[Assert\NotNull]
    private ?Program $program = null;

    #[ORM\ManyToOne(targetEntity: PeriodGroup::class, inversedBy: 'programPeriodGroups')]
    #[ORM\JoinColumn(name: 'period_group_id', nullable: false)]
    #[Assert\NotNull]
    private ?PeriodGroup $periodGroup = null;

    #[ORM\Column]
    #[Assert\NotNull]
    #[Assert\Positive]
    private int $priority;

    public function __construct(Program $program, PeriodGroup $periodGroup, int $priority)
    {
        $this->program = $program;
        $this->periodGroup = $periodGroup;
        $this->priority = $priority;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProgram(): ?Program
    {
        return $this->program;
    }

    public function getPeriodGroup(): ?PeriodGroup
    {
        return $this->periodGroup;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): static
    {
        $this->priority = $priority;

        return $this;
    }
}
