<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SelfAssessmentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The estimate a student makes of their own grade, for an assignment of the Autoévaluation nature
 * (design_handoff_carnet_de_notes, PROMPT_MODIFICATIONS §9, screens 5b/5c).
 *
 * The row exists from the first draft (« Reprendre plus tard »); $validatedAt marks the move to the
 * final estimate, which cannot be taken up again - that is the promise made to the student on the
 * entry screen, and what makes the comparison honest.
 *
 * $estimatedValue is always filled in, including when the evaluation carries a detailed rubric: it is
 * then the sum of the $answers, frozen on submission rather than recomputed on reading, so that a
 * rubric edited afterwards does not rewrite a student's estimate.
 */
#[ORM\Entity(repositoryClass: SelfAssessmentRepository::class)]
#[ORM\Table(name: 'self_assessment')]
#[ORM\UniqueConstraint(name: 'uniq_self_assessment_student', columns: ['assignment_id', 'student_id'])]
class SelfAssessment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Assignment::class)]
    #[ORM\JoinColumn(name: 'assignment_id', nullable: false, onDelete: 'CASCADE')]
    private ?Assignment $assignment = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false)]
    private ?User $student = null;

    #[ORM\Column(name: 'estimated_value', nullable: true)]
    private ?float $estimatedValue = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'validated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validatedAt = null;

    /** @var Collection<int, SelfAssessmentAnswer> */
    #[ORM\OneToMany(targetEntity: SelfAssessmentAnswer::class, mappedBy: 'selfAssessment', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $answers;

    public function __construct(Assignment $assignment, User $student)
    {
        $this->assignment = $assignment;
        $this->student = $student;
        $this->updatedAt = new \DateTimeImmutable();
        $this->answers = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAssignment(): ?Assignment
    {
        return $this->assignment;
    }

    public function getStudent(): ?User
    {
        return $this->student;
    }

    public function getEstimatedValue(): ?float
    {
        return $this->estimatedValue;
    }

    public function setEstimatedValue(?float $estimatedValue): static
    {
        $this->estimatedValue = $estimatedValue;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getValidatedAt(): ?\DateTimeImmutable
    {
        return $this->validatedAt;
    }

    public function validate(): static
    {
        $this->validatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function isValidated(): bool
    {
        return null !== $this->validatedAt;
    }

    /** @return Collection<int, SelfAssessmentAnswer> */
    public function getAnswers(): Collection
    {
        return $this->answers;
    }

    public function addAnswer(SelfAssessmentAnswer $answer): static
    {
        if (!$this->answers->contains($answer)) {
            $this->answers->add($answer);
        }

        return $this;
    }

    public function answerFor(EvaluationRubricQuestion $question): ?SelfAssessmentAnswer
    {
        foreach ($this->answers as $answer) {
            if ($answer->getQuestion() === $question) {
                return $answer;
            }
        }

        return null;
    }
}
