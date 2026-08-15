<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\UfaActivityType;
use App\Repository\UfaActivityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Timestamped log of the UFA's actions - booklet signatures, reminders - meant for the screens that
 * follow the latest events.
 *
 * Append-only: a row is never modified nor deleted, and nothing in the application uses it as a
 * source of truth. That is what allows writing to it AFTER the business flush, with no shared
 * transaction: a log must not be able to make the action it observes fail.
 *
 * $payload keeps the snapshot of the names at the time of the facts. The foreign keys serve to
 * filter and to navigate, the payload to render the sentence - a deactivated alternation, a renamed
 * account or a deleted period thus leave a history that is still legible.
 *
 * No purge here, unlike App\Entity\PlatformActivity: the volume is small (a few rows per alternation
 * and per period) and these events are the story of a booklet.
 */
#[ORM\Entity(repositoryClass: UfaActivityRepository::class)]
#[ORM\Table(name: 'ufa_activity')]
#[ORM\Index(name: 'idx_ufa_activity_occurred', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_ufa_activity_tutor_link', columns: ['tutor_link_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_ufa_activity_program', columns: ['program_id', 'occurred_at'])]
class UfaActivity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'occurred_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(length: 60, enumType: UfaActivityType::class)]
    private UfaActivityType $type;

    // Null for whatever no human triggered - nothing today, but an automatic reminder has long been
    // planned (see InternshipReminder::$auto).
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'actor_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    #[ORM\ManyToOne(targetEntity: InternshipTutorLink::class)]
    #[ORM\JoinColumn(name: 'tutor_link_id', nullable: true, onDelete: 'SET NULL')]
    private ?InternshipTutorLink $tutorLink = null;

    // Null for the 3 engagement signatures, which bear on no period.
    #[ORM\ManyToOne(targetEntity: InternshipEvaluationPeriod::class)]
    #[ORM\JoinColumn(name: 'evaluation_period_id', nullable: true, onDelete: 'SET NULL')]
    private ?InternshipEvaluationPeriod $evaluationPeriod = null;

    // Denormalised from the alternation: the UFA screens filter by program, and doing so by joining
    // on tutor_link would forbid keeping the trace after the alternation is deactivated.
    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: true, onDelete: 'SET NULL')]
    private ?Program $program = null;

    // Copied from InternshipTutorLink::$testAlternance: without it, a tracking screen would mix the
    // two worlds the rest of the UFA keeps strictly apart.
    #[ORM\Column(name: 'test_data', options: ['default' => false])]
    private bool $testData = false;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    /** @param array<string, string> $payload */
    public function __construct(UfaActivityType $type, ?User $actor, array $payload = [])
    {
        $this->type = $type;
        $this->actor = $actor;
        $this->payload = $payload;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getType(): UfaActivityType
    {
        return $this->type;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getTutorLink(): ?InternshipTutorLink
    {
        return $this->tutorLink;
    }

    public function setTutorLink(?InternshipTutorLink $tutorLink): static
    {
        $this->tutorLink = $tutorLink;

        return $this;
    }

    public function getEvaluationPeriod(): ?InternshipEvaluationPeriod
    {
        return $this->evaluationPeriod;
    }

    public function setEvaluationPeriod(?InternshipEvaluationPeriod $evaluationPeriod): static
    {
        $this->evaluationPeriod = $evaluationPeriod;

        return $this;
    }

    public function getProgram(): ?Program
    {
        return $this->program;
    }

    public function setProgram(?Program $program): static
    {
        $this->program = $program;

        return $this;
    }

    public function isTestData(): bool
    {
        return $this->testData;
    }

    public function setTestData(bool $testData): static
    {
        $this->testData = $testData;

        return $this;
    }

    /** @return array<string, string> */
    public function getPayload(): array
    {
        return $this->payload;
    }
}
