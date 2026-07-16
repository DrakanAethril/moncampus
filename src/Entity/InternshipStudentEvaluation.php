<?php

namespace App\Entity;

use App\Repository\InternshipStudentEvaluationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The student's own remarks for one InternshipEvaluationPeriod of their Program's Livret
 * Alternant - one row per (student, evaluationPeriod), edited in place across sessions.
 */
#[ORM\Entity(repositoryClass: InternshipStudentEvaluationRepository::class)]
#[ORM\Table(name: 'internship_student_evaluation')]
#[ORM\UniqueConstraint(name: 'internship_student_evaluation_unique', columns: ['student_id', 'evaluation_period_id'])]
class InternshipStudentEvaluation
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false)]
    private ?User $student = null;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: false)]
    private ?Program $program = null;

    #[ORM\ManyToOne(targetEntity: InternshipEvaluationPeriod::class)]
    #[ORM\JoinColumn(name: 'evaluation_period_id', nullable: false)]
    private ?InternshipEvaluationPeriod $evaluationPeriod = null;

    // Tracking only - never shown on the booklet/PDF, just lets staff tell apart their own
    // edits-on-behalf-of-a-student from the student's own submissions (see
    // ProgramInternshipController's staff evaluation-status screen). $validationDate already
    // covers "when" for both cases.
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'last_edited_by_id', nullable: true)]
    private ?User $lastEditedBy = null;

    #[ORM\Column(name: 'remarks_text', type: Types::TEXT, nullable: true)]
    private ?string $remarksText = null;

    #[ORM\Column(name: 'validation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $validationDate;

    public function __construct(User $student, Program $program, InternshipEvaluationPeriod $evaluationPeriod)
    {
        $this->student = $student;
        $this->program = $program;
        $this->evaluationPeriod = $evaluationPeriod;
        $this->validationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStudent(): ?User
    {
        return $this->student;
    }

    public function getProgram(): ?Program
    {
        return $this->program;
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
}
