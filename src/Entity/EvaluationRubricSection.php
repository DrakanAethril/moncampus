<?php

namespace App\Entity;

use App\Repository\EvaluationRubricSectionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

// One named band of an Evaluation's detailed barème (design's "Partie 1", "Partie 2"...).
#[ORM\Entity(repositoryClass: EvaluationRubricSectionRepository::class)]
#[ORM\Table(name: 'evaluation_rubric_section')]
class EvaluationRubricSection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Evaluation::class, inversedBy: 'rubricSections')]
    #[ORM\JoinColumn(name: 'evaluation_id', nullable: false)]
    private ?Evaluation $evaluation = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';

    #[ORM\Column]
    private int $position = 0;

    /** @var Collection<int, EvaluationRubricQuestion> */
    #[ORM\OneToMany(targetEntity: EvaluationRubricQuestion::class, mappedBy: 'section', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $questions;

    public function __construct(string $name, int $position = 0)
    {
        $this->name = $name;
        $this->position = $position;
        $this->questions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEvaluation(): ?Evaluation
    {
        return $this->evaluation;
    }

    public function setEvaluation(?Evaluation $evaluation): static
    {
        $this->evaluation = $evaluation;

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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    /** @return Collection<int, EvaluationRubricQuestion> */
    public function getQuestions(): Collection
    {
        return $this->questions;
    }

    public function addQuestion(EvaluationRubricQuestion $question): static
    {
        if (!$this->questions->contains($question)) {
            $this->questions->add($question);
            $question->setSection($this);
        }

        return $this;
    }

    public function removeQuestion(EvaluationRubricQuestion $question): static
    {
        $this->questions->removeElement($question);

        return $this;
    }
}
