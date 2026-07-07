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

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'inactive_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inactiveDate = null;

    public function __construct(string $label, Program $program)
    {
        $this->label = $label;
        $this->creationDate = new \DateTimeImmutable();
        $this->criteria = new ArrayCollection();
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
