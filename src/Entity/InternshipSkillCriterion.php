<?php

namespace App\Entity;

use App\Repository\InternshipSkillCriterionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One evaluable skill/competency (e.g. "Développer des interfaces utilisateurs") within an
 * InternshipSkillGroup on a Program's Livret Alternant referential.
 */
#[ORM\Entity(repositoryClass: InternshipSkillCriterionRepository::class)]
#[ORM\Table(name: 'internship_skill_criterion')]
class InternshipSkillCriterion
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

    #[ORM\ManyToOne(targetEntity: InternshipSkillGroup::class, inversedBy: 'criteria')]
    #[ORM\JoinColumn(name: 'skill_group_id', nullable: false)]
    #[Assert\NotNull]
    private ?InternshipSkillGroup $skillGroup = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'inactive_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inactiveDate = null;

    public function __construct(string $label, InternshipSkillGroup $skillGroup)
    {
        $this->label = $label;
        $this->creationDate = new \DateTimeImmutable();
        $this->setSkillGroup($skillGroup);
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

    public function getSkillGroup(): ?InternshipSkillGroup
    {
        return $this->skillGroup;
    }

    public function setSkillGroup(?InternshipSkillGroup $skillGroup): static
    {
        $this->skillGroup = $skillGroup;

        // Keep the inverse side in sync in memory - Doctrine only populates it from a fresh
        // query, not automatically from setting the owning side.
        if (null !== $skillGroup && !$skillGroup->getCriteria()->contains($this)) {
            $skillGroup->getCriteria()->add($this);
        }

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
