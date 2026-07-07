<?php

namespace App\Entity;

use App\Repository\InternshipStudentEvaluationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The student's own remarks for one Period of their Program's Livret Alternant - one row per
 * (student, period), edited in place across sessions.
 */
#[ORM\Entity(repositoryClass: InternshipStudentEvaluationRepository::class)]
#[ORM\Table(name: 'internship_student_evaluation')]
#[ORM\UniqueConstraint(name: 'internship_student_evaluation_unique', columns: ['student_id', 'period_id'])]
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

    #[ORM\ManyToOne(targetEntity: Period::class)]
    #[ORM\JoinColumn(name: 'period_id', nullable: false)]
    private ?Period $period = null;

    #[ORM\Column(name: 'remarks_text', type: Types::TEXT, nullable: true)]
    private ?string $remarksText = null;

    #[ORM\Column(name: 'validation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $validationDate;

    public function __construct(User $student, Program $program, Period $period)
    {
        $this->student = $student;
        $this->program = $program;
        $this->period = $period;
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

    public function getPeriod(): ?Period
    {
        return $this->period;
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
