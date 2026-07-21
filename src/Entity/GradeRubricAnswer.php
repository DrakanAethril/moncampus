<?php

namespace App\Entity;

use App\Repository\GradeRubricAnswerRepository;
use Doctrine\ORM\Mapping as ORM;

// One student's answer to one EvaluationRubricQuestion. $pointsAwarded is capped at the
// question's maxPoints server-side (App\Service\EvaluationAverageCalculator) same as the design's
// qSet() does client-side; $notTested mirrors GradeStatus::NotTested but at question grain (design's
// per-question "NT") - it contributes 0 to the summed Grade::$value without blocking the rest of
// the row from being gradable.
#[ORM\Entity(repositoryClass: GradeRubricAnswerRepository::class)]
#[ORM\Table(name: 'grade_rubric_answer')]
#[ORM\UniqueConstraint(name: 'uniq_grade_question', columns: ['grade_id', 'question_id'])]
class GradeRubricAnswer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Grade::class, inversedBy: 'rubricAnswers')]
    #[ORM\JoinColumn(name: 'grade_id', nullable: false)]
    private ?Grade $grade = null;

    #[ORM\ManyToOne(targetEntity: EvaluationRubricQuestion::class)]
    #[ORM\JoinColumn(name: 'question_id', nullable: false)]
    private ?EvaluationRubricQuestion $question = null;

    #[ORM\Column(name: 'points_awarded', nullable: true)]
    private ?float $pointsAwarded = null;

    #[ORM\Column(name: 'not_tested')]
    private bool $notTested = false;

    public function __construct(Grade $grade, EvaluationRubricQuestion $question)
    {
        $this->grade = $grade;
        $this->question = $question;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGrade(): ?Grade
    {
        return $this->grade;
    }

    public function setGrade(?Grade $grade): static
    {
        $this->grade = $grade;

        return $this;
    }

    public function getQuestion(): ?EvaluationRubricQuestion
    {
        return $this->question;
    }

    public function getPointsAwarded(): ?float
    {
        return $this->pointsAwarded;
    }

    public function setPointsAwarded(?float $pointsAwarded): static
    {
        $this->pointsAwarded = $pointsAwarded;

        return $this;
    }

    public function isNotTested(): bool
    {
        return $this->notTested;
    }

    public function setNotTested(bool $notTested): static
    {
        $this->notTested = $notTested;

        return $this;
    }
}
