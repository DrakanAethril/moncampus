<?php

namespace App\Entity;

use App\Repository\InternshipEvaluationPeriodRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A named date range a Program's Livret Alternant evaluations (tutor/student/team) are due within
 * - deliberately independent of Period/PeriodType/PeriodGroup, which define the alternance
 * calendar (classroom weeks vs. company weeks) and are no longer fit for this purpose: staff had
 * no way to mark which Period actually represented company time, so the tutor's own evaluation
 * screen ended up asking for an evaluation against every active Period in the Program's calendar,
 * vacations included. This entity replaces Period on InternshipTutorEvaluation/
 * InternshipStudentEvaluation/InternshipTeamEvaluation's own $evaluationPeriod field.
 */
#[ORM\Entity(repositoryClass: InternshipEvaluationPeriodRepository::class)]
#[ORM\Table(name: 'internship_evaluation_period')]
class InternshipEvaluationPeriod
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';

    // Date only, no time - deliberately Types::DATE_IMMUTABLE, matching Period's own convention.
    #[ORM\Column(name: 'start_date', type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(name: 'end_date', type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    #[Assert\GreaterThan(propertyPath: 'startDate')]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: false)]
    #[Assert\NotNull]
    private ?Program $program = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'inactive_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inactiveDate = null;

    public function __construct(Program $program)
    {
        $this->program = $program;
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(?\DateTimeImmutable $startDate): static
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(?\DateTimeImmutable $endDate): static
    {
        $this->endDate = $endDate;

        return $this;
    }

    public function getProgram(): ?Program
    {
        return $this->program;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    public function getInactiveDate(): ?\DateTimeImmutable
    {
        return $this->inactiveDate;
    }

    public function setInactiveDate(?\DateTimeImmutable $inactiveDate): static
    {
        $this->inactiveDate = $inactiveDate;

        return $this;
    }

    // Used by the staff evaluation-status screen (ProgramInternshipController) to classify a
    // not-yet-submitted evaluation as "en attente" vs. "en retard" - computed live against now(),
    // same "no cron" convention as Assignment::isLate()/SignupList::isRegistrationOpen().
    public function isPast(): bool
    {
        return $this->endDate < new \DateTimeImmutable();
    }
}
