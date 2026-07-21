<?php

namespace App\Entity;

use App\Enum\GradeStatus;
use App\Repository\GradeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One student's result for one Evaluation. Only created once a teacher actually enters something
 * for that student (design's "empty" cell kind is simply "no Grade row" - not a persisted status).
 * $value holds the raw entered/summed points out of Evaluation::$scale for GradeStatus::Normal and
 * GradeStatus::Excluded (the design's "(12)" parenthesised, deliberately-not-counted note); it
 * stays null for Absent/NotEvaluated/NotTested. When the Evaluation carries a detailed barème,
 * $value is instead computed from summing $rubricAnswers (see
 * App\Service\EvaluationAverageCalculator) rather than typed directly.
 */
#[ORM\Entity(repositoryClass: GradeRepository::class)]
#[ORM\Table(name: 'grade')]
#[ORM\UniqueConstraint(name: 'uniq_evaluation_student', columns: ['evaluation_id', 'student_id'])]
class Grade
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Evaluation::class, inversedBy: 'grades')]
    #[ORM\JoinColumn(name: 'evaluation_id', nullable: false)]
    private ?Evaluation $evaluation = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false)]
    private ?User $student = null;

    #[ORM\Column(length: 20, enumType: GradeStatus::class)]
    private GradeStatus $status = GradeStatus::Normal;

    #[ORM\Column(nullable: true)]
    private ?float $value = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'graded_by_id', nullable: true)]
    private ?User $gradedBy = null;

    #[ORM\Column(name: 'graded_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $gradedAt = null;

    /** @var Collection<int, GradeRubricAnswer> */
    #[ORM\OneToMany(targetEntity: GradeRubricAnswer::class, mappedBy: 'grade', cascade: ['persist'], orphanRemoval: true)]
    private Collection $rubricAnswers;

    #[ORM\OneToOne(targetEntity: GradeAudioComment::class, mappedBy: 'grade', cascade: ['persist', 'remove'])]
    private ?GradeAudioComment $audioComment = null;

    public function __construct(Evaluation $evaluation, User $student)
    {
        $this->evaluation = $evaluation;
        $this->student = $student;
        $this->rubricAnswers = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEvaluation(): ?Evaluation
    {
        return $this->evaluation;
    }

    public function getStudent(): ?User
    {
        return $this->student;
    }

    public function getStatus(): GradeStatus
    {
        return $this->status;
    }

    public function setStatus(GradeStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getValue(): ?float
    {
        return $this->value;
    }

    public function setValue(?float $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function getGradedBy(): ?User
    {
        return $this->gradedBy;
    }

    public function setGradedBy(?User $gradedBy): static
    {
        $this->gradedBy = $gradedBy;

        return $this;
    }

    public function getGradedAt(): ?\DateTimeImmutable
    {
        return $this->gradedAt;
    }

    public function setGradedAt(?\DateTimeImmutable $gradedAt): static
    {
        $this->gradedAt = $gradedAt;

        return $this;
    }

    /** @return Collection<int, GradeRubricAnswer> */
    public function getRubricAnswers(): Collection
    {
        return $this->rubricAnswers;
    }

    public function addRubricAnswer(GradeRubricAnswer $answer): static
    {
        if (!$this->rubricAnswers->contains($answer)) {
            $this->rubricAnswers->add($answer);
            $answer->setGrade($this);
        }

        return $this;
    }

    public function getAudioComment(): ?GradeAudioComment
    {
        return $this->audioComment;
    }
}
