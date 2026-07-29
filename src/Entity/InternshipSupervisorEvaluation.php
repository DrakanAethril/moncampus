<?php

namespace App\Entity;

use App\Repository\InternshipSupervisorEvaluationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The "chargé de suivi du centre de formation"'s closure of one InternshipEvaluationPeriod of one
 * InternshipTutorLink's livret - the 4th and last role in the period's signature sequence (after
 * tutor, student, team). Doesn't carry its own remarks: steps 1-2 of its wizard directly edit the
 * shared InternshipTutorEvaluation's behavior/skill collections, step 3 edits the shared
 * InternshipTutorEvaluation/InternshipStudentEvaluation/InternshipTeamEvaluation remarks, and this
 * row only exists to record the final "Signer et clôturer la période" act. $closedAt !== null is
 * the single source of truth that freezes the whole period read-only for every other role.
 */
#[ORM\Entity(repositoryClass: InternshipSupervisorEvaluationRepository::class)]
#[ORM\Table(name: 'internship_supervisor_evaluation')]
#[ORM\UniqueConstraint(name: 'internship_supervisor_evaluation_unique', columns: ['tutor_link_id', 'evaluation_period_id'])]
class InternshipSupervisorEvaluation
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

    #[ORM\Column(name: 'supervisor_signed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $supervisorSignedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'supervisor_signed_by_id', nullable: true)]
    private ?User $supervisorSignedBy = null;

    // Kept as a separate field from $supervisorSignedAt (mirroring the spec's own PeriodRecord
    // shape) even though this app's wizard sets both atomically in the same click - see
    // AlternancePeriodWizardService::closePeriod().
    #[ORM\Column(name: 'closed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'closed_by_id', nullable: true)]
    private ?User $closedBy = null;

    public function __construct(InternshipTutorLink $tutorLink, InternshipEvaluationPeriod $evaluationPeriod)
    {
        $this->tutorLink = $tutorLink;
        $this->evaluationPeriod = $evaluationPeriod;
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

    public function getSupervisorSignedAt(): ?\DateTimeImmutable
    {
        return $this->supervisorSignedAt;
    }

    public function setSupervisorSignedAt(?\DateTimeImmutable $supervisorSignedAt): static
    {
        $this->supervisorSignedAt = $supervisorSignedAt;

        return $this;
    }

    public function getSupervisorSignedBy(): ?User
    {
        return $this->supervisorSignedBy;
    }

    public function setSupervisorSignedBy(?User $supervisorSignedBy): static
    {
        $this->supervisorSignedBy = $supervisorSignedBy;

        return $this;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function setClosedAt(?\DateTimeImmutable $closedAt): static
    {
        $this->closedAt = $closedAt;

        return $this;
    }

    public function getClosedBy(): ?User
    {
        return $this->closedBy;
    }

    public function setClosedBy(?User $closedBy): static
    {
        $this->closedBy = $closedBy;

        return $this;
    }

    public function isClosed(): bool
    {
        return null !== $this->closedAt;
    }
}
