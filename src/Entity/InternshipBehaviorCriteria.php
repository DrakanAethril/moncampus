<?php

namespace App\Entity;

use App\Repository\InternshipBehaviorCriteriaRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One row (e.g. "Ponctualité") of the establishment-wide behavior rubric shown on the Livret
 * Alternant booklet, always rated on its own fixed 5-level scale (see InternshipBehaviorLevel).
 */
#[ORM\Entity(repositoryClass: InternshipBehaviorCriteriaRepository::class)]
#[ORM\Table(name: 'internship_behavior_criteria')]
class InternshipBehaviorCriteria
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

    #[ORM\Column(name: 'order_index')]
    private int $orderIndex = 0;

    /** @var Collection<int, InternshipBehaviorLevel> */
    #[ORM\OneToMany(targetEntity: InternshipBehaviorLevel::class, mappedBy: 'behaviorCriteria', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['levelNumber' => 'ASC'])]
    private Collection $levels;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'inactive_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inactiveDate = null;

    public function __construct(string $label = '')
    {
        $this->label = $label;
        $this->creationDate = new \DateTimeImmutable();
        $this->levels = new ArrayCollection();
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

    public function getOrderIndex(): int
    {
        return $this->orderIndex;
    }

    public function setOrderIndex(int $orderIndex): static
    {
        $this->orderIndex = $orderIndex;

        return $this;
    }

    /** @return Collection<int, InternshipBehaviorLevel> */
    public function getLevels(): Collection
    {
        return $this->levels;
    }

    public function addLevel(InternshipBehaviorLevel $level): static
    {
        if (!$this->levels->contains($level)) {
            $this->levels->add($level);
            $level->setBehaviorCriteria($this);
        }

        return $this;
    }

    public function removeLevel(InternshipBehaviorLevel $level): static
    {
        if ($this->levels->removeElement($level)) {
            if ($level->getBehaviorCriteria() === $this) {
                $level->setBehaviorCriteria(null);
            }
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
