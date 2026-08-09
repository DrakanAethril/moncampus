<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SelfAssessmentAnswerRepository;
use Doctrine\ORM\Mapping as ORM;

// L'estimation d'un étudiant sur une question du barème détaillé - le pendant de
// GradeRubricAnswer, côté élève. Pas d'équivalent du « non traitée » (NT) de l'enseignant :
// l'écran 5b exige une valeur pour chaque question avant de laisser valider, une question ratée
// s'estime donc à 0.
#[ORM\Entity(repositoryClass: SelfAssessmentAnswerRepository::class)]
#[ORM\Table(name: 'self_assessment_answer')]
#[ORM\UniqueConstraint(name: 'uniq_self_assessment_question', columns: ['self_assessment_id', 'question_id'])]
class SelfAssessmentAnswer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SelfAssessment::class, inversedBy: 'answers')]
    #[ORM\JoinColumn(name: 'self_assessment_id', nullable: false, onDelete: 'CASCADE')]
    private ?SelfAssessment $selfAssessment = null;

    #[ORM\ManyToOne(targetEntity: EvaluationRubricQuestion::class)]
    #[ORM\JoinColumn(name: 'question_id', nullable: false, onDelete: 'CASCADE')]
    private ?EvaluationRubricQuestion $question = null;

    #[ORM\Column(name: 'estimated_points', nullable: true)]
    private ?float $estimatedPoints = null;

    public function __construct(SelfAssessment $selfAssessment, EvaluationRubricQuestion $question)
    {
        $this->selfAssessment = $selfAssessment;
        $this->question = $question;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSelfAssessment(): ?SelfAssessment
    {
        return $this->selfAssessment;
    }

    public function getQuestion(): ?EvaluationRubricQuestion
    {
        return $this->question;
    }

    public function getEstimatedPoints(): ?float
    {
        return $this->estimatedPoints;
    }

    public function setEstimatedPoints(?float $estimatedPoints): static
    {
        $this->estimatedPoints = $estimatedPoints;

        return $this;
    }
}
