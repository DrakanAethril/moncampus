<?php

namespace App\Entity;

use App\Repository\InternshipTutorEvaluationSkillRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One rated row (against a Skill) of an InternshipTutorEvaluation - the level is nullable so a
 * tutor can save a partially-filled evaluation across sessions before every skill has been rated.
 */
#[ORM\Entity(repositoryClass: InternshipTutorEvaluationSkillRepository::class)]
#[ORM\Table(name: 'internship_tutor_evaluation_skill')]
class InternshipTutorEvaluationSkill
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: InternshipTutorEvaluation::class, inversedBy: 'skillEvaluations')]
    #[ORM\JoinColumn(name: 'tutor_evaluation_id', nullable: false)]
    private ?InternshipTutorEvaluation $tutorEvaluation = null;

    #[ORM\ManyToOne(targetEntity: Skill::class)]
    #[ORM\JoinColumn(name: 'skill_id', nullable: false)]
    private ?Skill $skill = null;

    #[ORM\ManyToOne(targetEntity: InternshipSkillLevel::class)]
    #[ORM\JoinColumn(name: 'skill_level_id', nullable: true)]
    private ?InternshipSkillLevel $skillLevel = null;

    public function __construct(Skill $skill)
    {
        $this->skill = $skill;
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

    public function getSkill(): ?Skill
    {
        return $this->skill;
    }

    public function getSkillLevel(): ?InternshipSkillLevel
    {
        return $this->skillLevel;
    }

    public function setSkillLevel(?InternshipSkillLevel $skillLevel): static
    {
        $this->skillLevel = $skillLevel;

        return $this;
    }
}
