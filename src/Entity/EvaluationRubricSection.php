<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RubricSectionKind;
use App\Repository\EvaluationRubricSectionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

// One band of an Evaluation's detailed barème: a named part (design's "Partie 1", "Partie 2"...),
// or - since $kind exists - the single Bonus or Malus band pinned under them, which carries no name
// of its own (App\Enum\RubricSectionKind says why).
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

    // Blank on a Bonus/Malus band, which is labelled from its $kind rather than from the database.
    #[ORM\Column(length: 255)]
    #[Assert\Length(max: 255)]
    private string $name = '';

    /**
     * Added with a DEFAULT so the existing rows take it during the ALTER; the mapping declares it
     * too, otherwise doctrine:schema:validate reports the drift at every run.
     */
    #[ORM\Column(length: 20, options: ['default' => 'standard'], enumType: RubricSectionKind::class)]
    private RubricSectionKind $kind = RubricSectionKind::Standard;

    #[ORM\Column]
    private int $position = 0;

    /** @var Collection<int, EvaluationRubricQuestion> */
    #[ORM\OneToMany(targetEntity: EvaluationRubricQuestion::class, mappedBy: 'section', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $questions;

    public function __construct(string $name, int $position = 0, RubricSectionKind $kind = RubricSectionKind::Standard)
    {
        $this->name = $name;
        $this->position = $position;
        $this->kind = $kind;
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

    public function getKind(): RubricSectionKind
    {
        return $this->kind;
    }

    public function setKind(RubricSectionKind $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    // The points this band adds to (or takes from) the grade when every question is at its maximum.
    public function getMaxPoints(): float
    {
        $total = 0.0;
        foreach ($this->questions as $question) {
            $total += $question->getMaxPoints();
        }

        return round($total, 2);
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
