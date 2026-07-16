<?php

namespace App\Entity;

use App\Repository\InternshipTutorEvaluationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The entreprise tutor's rating of a student's behavior and skills for one
 * InternshipEvaluationPeriod of one InternshipTutorLink contract - one row per (tutorLink,
 * evaluationPeriod), edited in place across sessions rather than locked after a first submit (see
 * InternshipTutorEvaluationController).
 */
#[ORM\Entity(repositoryClass: InternshipTutorEvaluationRepository::class)]
#[ORM\Table(name: 'internship_tutor_evaluation')]
#[ORM\UniqueConstraint(name: 'internship_tutor_evaluation_unique', columns: ['tutor_link_id', 'evaluation_period_id'])]
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

    #[ORM\ManyToOne(targetEntity: InternshipEvaluationPeriod::class)]
    #[ORM\JoinColumn(name: 'evaluation_period_id', nullable: false)]
    private ?InternshipEvaluationPeriod $evaluationPeriod = null;

    // Tracking only - never shown on the booklet/PDF, just lets staff tell apart their own
    // edits-on-behalf-of-a-tutor from the tutor's own submissions (see ProgramInternshipController's
    // staff evaluation-status screen). $validationDate already covers "when" for both cases.
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'last_edited_by_id', nullable: true)]
    private ?User $lastEditedBy = null;

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

    // Ordered by id (insertion order) so the skill grid renders grouped by SkillGroup
    // consistently across saves - rows are always appended in the same skillGroup->skills
    // iteration order (see InternshipTutorEvaluationController::evaluate()).
    /** @var Collection<int, InternshipTutorEvaluationBehavior> */
    #[ORM\OneToMany(targetEntity: InternshipTutorEvaluationBehavior::class, mappedBy: 'tutorEvaluation', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $behaviorEvaluations;

    /** @var Collection<int, InternshipTutorEvaluationSkill> */
    #[ORM\OneToMany(targetEntity: InternshipTutorEvaluationSkill::class, mappedBy: 'tutorEvaluation', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $skillEvaluations;

    public function __construct(InternshipTutorLink $tutorLink, InternshipEvaluationPeriod $evaluationPeriod)
    {
        $this->tutorLink = $tutorLink;
        $this->evaluationPeriod = $evaluationPeriod;
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

    public function getEvaluationPeriod(): ?InternshipEvaluationPeriod
    {
        return $this->evaluationPeriod;
    }

    public function getLastEditedBy(): ?User
    {
        return $this->lastEditedBy;
    }

    public function setLastEditedBy(?User $lastEditedBy): static
    {
        $this->lastEditedBy = $lastEditedBy;

        return $this;
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

    // Used by templates/internship/booklet.html.twig to mark which level was actually selected
    // in the printed positioning grid, without the template iterating behaviorEvaluations itself.
    public function getBehaviorLevelFor(InternshipBehaviorCriteria $criteria): ?InternshipBehaviorLevel
    {
        foreach ($this->behaviorEvaluations as $behaviorEvaluation) {
            if ($behaviorEvaluation->getBehaviorCriteria() === $criteria) {
                return $behaviorEvaluation->getBehaviorLevel();
            }
        }

        return null;
    }

    // Same reasoning as getBehaviorLevelFor(), for the competency grid.
    public function getSkillLevelFor(Skill $skill): ?SkillLevel
    {
        foreach ($this->skillEvaluations as $skillEvaluation) {
            if ($skillEvaluation->getSkill() === $skill) {
                return $skillEvaluation->getSkillLevel();
            }
        }

        return null;
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
