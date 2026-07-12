<?php

namespace App\Entity;

use App\Repository\SkillGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A group of related skills/competencies (e.g. "Développer une application sécurisée") for the
 * Livret Alternant referential, each owning its own Skill rows evaluated per period against the
 * skill-level scale (InternshipSkillLevel) that applies to this group's Program.
 *
 * Always owned by exactly one Program - unlike InternshipSkillLevel, there is no Centre de
 * formation/shared definition for SkillGroup or Skill: every Program defines its own groups and
 * skills from scratch.
 */
#[ORM\Entity(repositoryClass: SkillGroupRepository::class)]
#[ORM\Table(name: 'skill_group')]
class SkillGroup
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $label;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: false)]
    private Program $program;

    /** @var Collection<int, Skill> */
    #[ORM\OneToMany(targetEntity: Skill::class, mappedBy: 'skillGroup')]
    private Collection $skills;

    // Empty means visible to every student regardless of Option; non-empty scopes this group (and
    // the booklet/evaluation form questions it produces) to only the students enrolled in one of
    // these Options - see ProgramStudentOptionRepository::findOptionsForStudent() and its use in
    // InternshipBookletBuilder/InternshipTutorEvaluationController::evaluate().
    /** @var Collection<int, Option> */
    #[ORM\ManyToMany(targetEntity: Option::class)]
    #[ORM\JoinTable(name: 'skill_group_option')]
    private Collection $options;

    // Both default true so every existing/newly-created group keeps showing up everywhere until a
    // teacher opts it out - see isVisibleForStudentOptions() for the pre-existing Option-based gate
    // this composes with in InternshipBookletBuilder/InternshipTutorEvaluationController.
    #[ORM\Column(name: 'visible_in_booklet', options: ['default' => true])]
    private bool $visibleInBooklet = true;

    // Not read anywhere yet - reserved for gating this group's future use outside the Livret
    // Alternant (e.g. qualifying a LessonSession by skill group).
    #[ORM\Column(name: 'visible_in_program', options: ['default' => true])]
    private bool $visibleInProgram = true;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'inactive_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inactiveDate = null;

    public function __construct(string $label, Program $program)
    {
        $this->label = $label;
        $this->creationDate = new \DateTimeImmutable();
        $this->skills = new ArrayCollection();
        $this->options = new ArrayCollection();
        $this->program = $program;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getProgram(): Program
    {
        return $this->program;
    }

    public function setProgram(Program $program): static
    {
        $this->program = $program;

        return $this;
    }

    /** @return Collection<int, Skill> */
    public function getSkills(): Collection
    {
        return $this->skills;
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

    // Shared by InternshipBookletBuilder and InternshipTutorEvaluationController::evaluate() so
    // a group's option-gating is only ever decided in one place.
    /** @param list<int> $studentOptionIds */
    public function isVisibleForStudentOptions(array $studentOptionIds): bool
    {
        if ($this->options->isEmpty()) {
            return true;
        }

        foreach ($this->options as $option) {
            if (\in_array($option->getId(), $studentOptionIds, true)) {
                return true;
            }
        }

        return false;
    }

    public function isVisibleInBooklet(): bool
    {
        return $this->visibleInBooklet;
    }

    public function setVisibleInBooklet(bool $visibleInBooklet): static
    {
        $this->visibleInBooklet = $visibleInBooklet;

        return $this;
    }

    public function isVisibleInProgram(): bool
    {
        return $this->visibleInProgram;
    }

    public function setVisibleInProgram(bool $visibleInProgram): static
    {
        $this->visibleInProgram = $visibleInProgram;

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
