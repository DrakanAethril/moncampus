<?php

namespace App\Entity;

use App\Repository\InternshipSkillGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A group of related skills/competencies (e.g. "Développer une application sécurisée") in one
 * Program's Livret Alternant referential - each group owns its own InternshipSkillCriterion
 * rows, evaluated per period against the establishment-wide InternshipSkillLevel scale.
 */
#[ORM\Entity(repositoryClass: InternshipSkillGroupRepository::class)]
#[ORM\Table(name: 'internship_skill_group')]
class InternshipSkillGroup
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
    #[Assert\NotNull]
    private ?Program $program = null;

    /** @var Collection<int, InternshipSkillCriterion> */
    #[ORM\OneToMany(targetEntity: InternshipSkillCriterion::class, mappedBy: 'skillGroup')]
    private Collection $criteria;

    // Empty means visible to every student on the Program regardless of Option; non-empty scopes
    // this group (and the booklet/evaluation form questions it produces) to only the students
    // enrolled in one of these Options - see ProgramStudentOptionRepository::findOptionsForStudent()
    // and its use in InternshipBookletBuilder/InternshipTutorEvaluationController::evaluate().
    /** @var Collection<int, Option> */
    #[ORM\ManyToMany(targetEntity: Option::class)]
    #[ORM\JoinTable(name: 'internship_skill_group_option')]
    private Collection $options;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'inactive_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inactiveDate = null;

    public function __construct(string $label, Program $program)
    {
        $this->label = $label;
        $this->creationDate = new \DateTimeImmutable();
        $this->criteria = new ArrayCollection();
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

    public function getProgram(): ?Program
    {
        return $this->program;
    }

    public function setProgram(?Program $program): static
    {
        $this->program = $program;

        return $this;
    }

    /** @return Collection<int, InternshipSkillCriterion> */
    public function getCriteria(): Collection
    {
        return $this->criteria;
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
