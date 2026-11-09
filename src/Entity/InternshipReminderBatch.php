<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ReminderBatchStatus;
use App\Enum\ReminderBatchStep;
use App\Repository\InternshipReminderBatchRepository;
use App\Service\ReminderBatch\ReminderBatchTarget;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A reminder batch of the UFA (design/validated/ufa-reminder-batches.md): one stage of the livret,
 * in every formation in alternance or in a few, sent by the worker at the platform's pace.
 *
 * Its lines (InternshipReminderBatchItem) are frozen at launch (R1) and read again before each mail
 * (R2). The batch's figures are never stored: they are a GROUP BY over its lines, a batch holding a
 * few hundred at most. A batch is never deleted - cancelling one keeps what was sent (R7).
 */
#[ORM\Entity(repositoryClass: InternshipReminderBatchRepository::class)]
#[ORM\Table(name: 'internship_reminder_batch')]
#[ORM\Index(name: 'idx_internship_reminder_batch_status', columns: ['status'])]
class InternshipReminderBatch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30, enumType: ReminderBatchStep::class)]
    private ReminderBatchStep $step;

    // The bilan's rank in each formation (1, 2…), null for the engagement.
    #[ORM\Column(name: 'period_rank', type: Types::SMALLINT, nullable: true)]
    private ?int $periodRank = null;

    // « Toutes les formations », as read at launch: $programs then holds every one of them.
    #[ORM\Column(name: 'all_programs')]
    private bool $allPrograms;

    /** @var Collection<int, Program> */
    #[ORM\ManyToMany(targetEntity: Program::class)]
    #[ORM\JoinTable(name: 'internship_reminder_batch_program')]
    private Collection $programs;

    #[ORM\ManyToOne(targetEntity: SchoolYear::class)]
    #[ORM\JoinColumn(name: 'school_year_id', nullable: false)]
    private ?SchoolYear $schoolYear = null;

    #[ORM\Column(length: 20, enumType: ReminderBatchStatus::class)]
    private ReminderBatchStatus $status = ReminderBatchStatus::Running;

    // Who launched it - and who signs every reminder it sends (R4).
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: false)]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'paused_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $pausedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'paused_by_id', nullable: true)]
    private ?User $pausedBy = null;

    #[ORM\Column(name: 'finished_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(name: 'cancelled_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'cancelled_by_id', nullable: true)]
    private ?User $cancelledBy = null;

    /**
     * @param list<Program> $programs
     */
    public function __construct(ReminderBatchTarget $target, bool $allPrograms, array $programs, SchoolYear $schoolYear, User $createdBy, \DateTimeImmutable $createdAt)
    {
        $this->step = $target->step;
        $this->periodRank = $target->rank;
        $this->allPrograms = $allPrograms;
        $this->programs = new ArrayCollection($programs);
        $this->schoolYear = $schoolYear;
        $this->createdBy = $createdBy;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTarget(): ReminderBatchTarget
    {
        return new ReminderBatchTarget($this->step, $this->periodRank);
    }

    public function getStep(): ReminderBatchStep
    {
        return $this->step;
    }

    public function getPeriodRank(): ?int
    {
        return $this->periodRank;
    }

    public function isAllPrograms(): bool
    {
        return $this->allPrograms;
    }

    /** @return list<Program> */
    public function getPrograms(): array
    {
        $programs = $this->programs->toArray();
        usort($programs, static fn (Program $a, Program $b): int => strnatcasecmp((string) $a->getDisplayShortName(), (string) $b->getDisplayShortName()));

        return $programs;
    }

    public function getSchoolYear(): ?SchoolYear
    {
        return $this->schoolYear;
    }

    public function getStatus(): ReminderBatchStatus
    {
        return $this->status;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPausedAt(): ?\DateTimeImmutable
    {
        return $this->pausedAt;
    }

    public function getPausedBy(): ?User
    {
        return $this->pausedBy;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function getCancelledBy(): ?User
    {
        return $this->cancelledBy;
    }

    public function pause(User $by, \DateTimeImmutable $at): void
    {
        if (ReminderBatchStatus::Running !== $this->status) {
            throw new \LogicException('Only a running batch is paused.');
        }

        $this->status = ReminderBatchStatus::Paused;
        $this->pausedAt = $at;
        $this->pausedBy = $by;
    }

    public function resume(): void
    {
        if (ReminderBatchStatus::Paused !== $this->status) {
            throw new \LogicException('Only a paused batch is resumed.');
        }

        $this->status = ReminderBatchStatus::Running;
        $this->pausedAt = null;
        $this->pausedBy = null;
    }

    public function cancel(User $by, \DateTimeImmutable $at): void
    {
        if (!$this->status->isActive()) {
            throw new \LogicException('Only a running or paused batch is cancelled.');
        }

        $this->status = ReminderBatchStatus::Cancelled;
        $this->cancelledAt = $at;
        $this->cancelledBy = $by;
    }

    public function finish(\DateTimeImmutable $at): void
    {
        $this->status = ReminderBatchStatus::Finished;
        $this->finishedAt = $at;
    }
}
