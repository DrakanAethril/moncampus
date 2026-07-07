<?php

namespace App\Entity;

use App\Repository\InternshipTutorEvaluationBehaviorRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One rated row (against an InternshipBehaviorCriteria) of an InternshipTutorEvaluation - the
 * level is nullable so a tutor can save a partially-filled evaluation across sessions before
 * every criteria has been rated.
 */
#[ORM\Entity(repositoryClass: InternshipTutorEvaluationBehaviorRepository::class)]
#[ORM\Table(name: 'internship_tutor_evaluation_behavior')]
class InternshipTutorEvaluationBehavior
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: InternshipTutorEvaluation::class, inversedBy: 'behaviorEvaluations')]
    #[ORM\JoinColumn(name: 'tutor_evaluation_id', nullable: false)]
    private ?InternshipTutorEvaluation $tutorEvaluation = null;

    #[ORM\ManyToOne(targetEntity: InternshipBehaviorCriteria::class)]
    #[ORM\JoinColumn(name: 'behavior_criteria_id', nullable: false)]
    private ?InternshipBehaviorCriteria $behaviorCriteria = null;

    #[ORM\ManyToOne(targetEntity: InternshipBehaviorLevel::class)]
    #[ORM\JoinColumn(name: 'behavior_level_id', nullable: true)]
    private ?InternshipBehaviorLevel $behaviorLevel = null;

    public function __construct(InternshipBehaviorCriteria $behaviorCriteria)
    {
        $this->behaviorCriteria = $behaviorCriteria;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTutorEvaluation(): ?InternshipTutorEvaluation
    {
        return $this->tutorEvaluation;
    }

    public function setTutorEvaluation(?InternshipTutorEvaluation $tutorEvaluation): static
    {
        $this->tutorEvaluation = $tutorEvaluation;

        return $this;
    }

    public function getBehaviorCriteria(): ?InternshipBehaviorCriteria
    {
        return $this->behaviorCriteria;
    }

    public function getBehaviorLevel(): ?InternshipBehaviorLevel
    {
        return $this->behaviorLevel;
    }

    public function setBehaviorLevel(?InternshipBehaviorLevel $behaviorLevel): static
    {
        $this->behaviorLevel = $behaviorLevel;

        return $this;
    }
}
