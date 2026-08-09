<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InternshipTeamEvaluationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The pedagogical team's own remarks for one InternshipEvaluationPeriod of a student's Program
 * Livret Alternant ("Chargé(e) de suivi du centre de formation" in the reference document) - one
 * row per (student, evaluationPeriod), edited in place across sessions. Filled by staff (see
 * Program\InternshipTeamEvaluationController), not the tutor or the student themselves.
 */
#[ORM\Entity(repositoryClass: InternshipTeamEvaluationRepository::class)]
#[ORM\Table(name: 'internship_team_evaluation')]
#[ORM\UniqueConstraint(name: 'internship_team_evaluation_unique', columns: ['student_id', 'evaluation_period_id'])]
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

    #[ORM\ManyToOne(targetEntity: InternshipEvaluationPeriod::class)]
    #[ORM\JoinColumn(name: 'evaluation_period_id', nullable: false)]
    private ?InternshipEvaluationPeriod $evaluationPeriod = null;

    #[ORM\Column(name: 'remarks_text', type: Types::TEXT, nullable: true)]
    private ?string $remarksText = null;

    #[ORM\Column(name: 'validation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $validationDate;

    // Real lock, distinct from $validationDate - see InternshipTutorEvaluation::$signedAt for the
    // full rationale (same pattern here: the team's own "Signer et transmettre" on step 4).
    #[ORM\Column(name: 'signed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $signedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'signed_by_id', nullable: true)]
    private ?User $signedBy = null;

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

    public function getSignedAt(): ?\DateTimeImmutable
    {
        return $this->signedAt;
    }

    public function setSignedAt(?\DateTimeImmutable $signedAt): static
    {
        $this->signedAt = $signedAt;

        return $this;
    }

    public function getSignedBy(): ?User
    {
        return $this->signedBy;
    }

    public function setSignedBy(?User $signedBy): static
    {
        $this->signedBy = $signedBy;

        return $this;
    }

    public function isSigned(): bool
    {
        return null !== $this->signedAt;
    }
}
