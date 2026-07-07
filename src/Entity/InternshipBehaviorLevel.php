<?php

namespace App\Entity;

use App\Repository\InternshipBehaviorLevelRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One of the fixed 5 rating levels (1-5) of an InternshipBehaviorCriteria row on the Livret
 * Alternant behavior rubric. Not independently listed/deactivated - always managed together
 * with its parent criteria (a fixed-size collection in InternshipBehaviorCriteriaType), kept as
 * its own entity (not 5 flat columns on the criteria) purely so a later phase's tutor
 * evaluations can foreign-key a specific level row.
 */
#[ORM\Entity(repositoryClass: InternshipBehaviorLevelRepository::class)]
#[ORM\Table(name: 'internship_behavior_level')]
class InternshipBehaviorLevel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $label;

    #[ORM\Column(name: 'level_number')]
    private int $levelNumber;

    #[ORM\ManyToOne(targetEntity: InternshipBehaviorCriteria::class, inversedBy: 'levels')]
    #[ORM\JoinColumn(name: 'behavior_criteria_id', nullable: false)]
    private ?InternshipBehaviorCriteria $behaviorCriteria = null;

    public function __construct(int $levelNumber, string $label = '')
    {
        $this->levelNumber = $levelNumber;
        $this->label = $label;
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

    public function getLevelNumber(): int
    {
        return $this->levelNumber;
    }

    public function getBehaviorCriteria(): ?InternshipBehaviorCriteria
    {
        return $this->behaviorCriteria;
    }

    public function setBehaviorCriteria(?InternshipBehaviorCriteria $behaviorCriteria): static
    {
        $this->behaviorCriteria = $behaviorCriteria;

        return $this;
    }
}
