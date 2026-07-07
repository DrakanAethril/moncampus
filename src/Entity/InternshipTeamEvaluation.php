<?php

namespace App\Entity;

use App\Repository\InternshipTeamEvaluationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The pedagogical team's own remarks for one Period of a student's Program Livret Alternant
 * ("Chargé(e) de suivi du centre de formation" in the reference document) - one row per
 * (student, period), edited in place across sessions. Filled by staff (see
 * ProgramInternshipController), not the tutor or the student themselves.
 */
#[ORM\Entity(repositoryClass: InternshipTeamEvaluationRepository::class)]
#[ORM\Table(name: 'internship_team_evaluation')]
#[ORM\UniqueConstraint(name: 'internship_team_evaluation_unique', columns: ['student_id', 'period_id'])]
class InternshipTeamEvaluation
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
