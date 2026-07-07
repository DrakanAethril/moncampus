<?php

namespace App\Entity;

use App\Repository\InternshipTutorEvaluationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The entreprise tutor's rating of a student's behavior and skills for one Period of one
 * InternshipTutorLink contract - one row per (tutorLink, period), edited in place across
 * sessions rather than locked after a first submit (see InternshipTutorEvaluationController).
 */
#[ORM\Entity(repositoryClass: InternshipTutorEvaluationRepository::class)]
#[ORM\Table(name: 'internship_tutor_evaluation')]
#[ORM\UniqueConstraint(name: 'internship_tutor_evaluation_unique', columns: ['tutor_link_id', 'period_id'])]
class InternshipTutorEvaluation
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: InternshipTutorLink::class)]
    #[ORM\JoinColumn(name: 'tutor_link_id', nullable: false)]
    private ?InternshipTutorLink $tutorLink = null;

    #[ORM\ManyToOne(targetEntity: Period::class)]
    #[ORM\JoinColumn(name: 'period_id', nullable: false)]
    private ?Period $period = null;

    #[ORM\Column(name: 'strengths_text', type: Types::TEXT, nullable: true)]
    private ?string $strengthsText = null;

    #[ORM\Column(name: 'weaknesses_text', type: Types::TEXT, nullable: true)]
    private ?string $weaknessesText = null;

    #[ORM\Column(name: 'goals_text', type: Types::TEXT, nullable: true)]
    private ?string $goalsText = null;

    #[ORM\Column(name: 'remarks_text', type: Types::TEXT, nullable: true)]
    private ?string $remarksText = null;

    #[ORM\Column(name: 'validation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $validationDate;

    // Ordered by id (insertion order) so the skill grid renders grouped by InternshipSkillGroup
    // consistently across saves - rows are always appended in the same skillGroup->criteria
    // iteration order (see InternshipTutorEvaluationController::evaluate()).
    /** @var Collection<int, InternshipTutorEvaluationBehavior> */
    #[ORM\OneToMany(targetEntity: InternshipTutorEvaluationBehavior::class, mappedBy: 'tutorEvaluation', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $behaviorEvaluations;

    /** @var Collection<int, InternshipTutorEvaluationSkill> */
    #[ORM\OneToMany(targetEntity: InternshipTutorEvaluationSkill::class, mappedBy: 'tutorEvaluation', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $skillEvaluations;

    public function __construct(InternshipTutorLink $tutorLink, Period $period)
    {
        $this->tutorLink = $tutorLink;
        $this->period = $period;
        $this->validationDate = new \DateTimeImmutable();
        $this->behaviorEvaluations = new ArrayCollection();
        $this->skillEvaluations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTutorLink(): ?InternshipTutorLink
    {
        return $this->tutorLink;
    }

    public function getPeriod(): ?Period
    {
        return $this->period;
    }

    public function getStrengthsText(): ?string
    {
        return $this->strengthsText;
    }

    public function setStrengthsText(?string $strengthsText): static
    {
        $this->strengthsText = $strengthsText;

        return $this;
    }

    public function getWeaknessesText(): ?string
    {
        return $this->weaknessesText;
    }

    public function setWeaknessesText(?string $weaknessesText): static
    {
        $this->weaknessesText = $weaknessesText;

        return $this;
    }

    public function getGoalsText(): ?string
    {
        return $this->goalsText;
    }

    public function setGoalsText(?string $goalsText): static
    {
        $this->goalsText = $goalsText;

        return $this;
    }

    public function getRemarksText(): ?string
    {
        return $this->remarksText;
    }

    public function setRemarksText(?string $remarksText): static
    {
        $this->remarksText = $remarksText;

        return $this;
    }

    public function getValidationDate(): \DateTimeImmutable
    {
        return $this->validationDate;
    }

    public function setValidationDate(\DateTimeImmutable $validationDate): static
    {
        $this->validationDate = $validationDate;

        return $this;
    }

    /** @return Collection<int, InternshipTutorEvaluationBehavior> */
    public function getBehaviorEvaluations(): Collection
    {
        return $this->behaviorEvaluations;
    }

    public function addBehaviorEvaluation(InternshipTutorEvaluationBehavior $behaviorEvaluation): static
    {
        if (!$this->behaviorEvaluations->contains($behaviorEvaluation)) {
            $this->behaviorEvaluations->add($behaviorEvaluation);
            $behaviorEvaluation->setTutorEvaluation($this);
        }

        return $this;
    }

    // Never actually called (the 'behaviorEvaluations' CollectionType has allow_delete: false),
    // but the adder/remover pair must both exist for the form's by_reference: false mapping to
    // recognize this as a writable collection property.
    public function removeBehaviorEvaluation(InternshipTutorEvaluationBehavior $behaviorEvaluation): static
    {
        if ($this->behaviorEvaluations->removeElement($behaviorEvaluation)) {
            if ($behaviorEvaluation->getTutorEvaluation() === $this) {
                $behaviorEvaluation->setTutorEvaluation(null);
            }
        }

        return $this;
    }

    /** @return Collection<int, InternshipTutorEvaluationSkill> */
    public function getSkillEvaluations(): Collection
    {
        return $this->skillEvaluations;
    }

    public function addSkillEvaluation(InternshipTutorEvaluationSkill $skillEvaluation): static
    {
        if (!$this->skillEvaluations->contains($skillEvaluation)) {
            $this->skillEvaluations->add($skillEvaluation);
            $skillEvaluation->setTutorEvaluation($this);
        }

        return $this;
    }

    // Same reasoning as removeBehaviorEvaluation() - never actually called, but required to
    // exist alongside addSkillEvaluation() for the form's by_reference: false mapping.
    public function removeSkillEvaluation(InternshipTutorEvaluationSkill $skillEvaluation): static
    {
        if ($this->skillEvaluations->removeElement($skillEvaluation)) {
            if ($skillEvaluation->getTutorEvaluation() === $this) {
                $skillEvaluation->setTutorEvaluation(null);
            }
        }

        return $this;
    }
}
