<?php

namespace App\Entity;

use App\Repository\EvaluationRubricQuestionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

// One scored question row within an EvaluationRubricSection (design's "num"/"pts" columns -
// $label stays a free-text string since question numbering can be non-numeric, e.g. "2a").
#[ORM\Entity(repositoryClass: EvaluationRubricQuestionRepository::class)]
#[ORM\Table(name: 'evaluation_rubric_question')]
class EvaluationRubricQuestion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EvaluationRubricSection::class, inversedBy: 'questions')]
    #[ORM\JoinColumn(name: 'section_id', nullable: false)]
    private ?EvaluationRubricSection $section = null;

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank]
    private string $label = '';

    #[ORM\Column(name: 'max_points')]
    #[Assert\GreaterThan(0)]
    private float $maxPoints = 1.0;

    #[ORM\Column]
    private int $position = 0;

    public function __construct(string $label, float $maxPoints, int $position = 0)
    {
        $this->label = $label;
        $this->maxPoints = $maxPoints;
        $this->position = $position;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSection(): ?EvaluationRubricSection
    {
        return $this->section;
    }

    public function setSection(?EvaluationRubricSection $section): static
    {
        $this->section = $section;

        return $this;
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

    public function getMaxPoints(): float
    {
        return $this->maxPoints;
    }

    public function setMaxPoints(float $maxPoints): static
    {
        $this->maxPoints = $maxPoints;

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
}
