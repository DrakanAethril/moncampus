<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ReminderBatchExclusion;
use App\Enum\ReminderBatchItemStatus;
use App\Repository\InternshipReminderBatchItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One frozen line of a reminder batch: one alternance at the batch's step, for the bilan of its
 * formation that the batch's rank designates.
 *
 * The formation is stored on the line rather than read off the alternance: it is the one the batch
 * was launched on, which is what the screen groups by.
 */
#[ORM\Entity(repositoryClass: InternshipReminderBatchItemRepository::class)]
#[ORM\Table(name: 'internship_reminder_batch_item')]
#[ORM\UniqueConstraint(name: 'uniq_internship_reminder_batch_item_link', columns: ['batch_id', 'tutor_link_id'])]
#[ORM\Index(name: 'idx_internship_reminder_batch_item_status', columns: ['status'])]
class InternshipReminderBatchItem
{
    /** How many times a passing error is tried before the line fails. */
    public const int MAX_ATTEMPTS = 3;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: InternshipReminderBatch::class)]
    #[ORM\JoinColumn(name: 'batch_id', nullable: false, onDelete: 'CASCADE')]
    private ?InternshipReminderBatch $batch = null;

    #[ORM\ManyToOne(targetEntity: InternshipTutorLink::class)]
    #[ORM\JoinColumn(name: 'tutor_link_id', nullable: false, onDelete: 'CASCADE')]
    private ?InternshipTutorLink $tutorLink = null;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: false)]
    private ?Program $program = null;

    // The bilan of this formation the batch's rank designates; null for the engagement. SET NULL:
    // a deleted bilan leaves a line that is no longer concerned, not a batch that cannot load.
    #[ORM\ManyToOne(targetEntity: InternshipEvaluationPeriod::class)]
    #[ORM\JoinColumn(name: 'evaluation_period_id', nullable: true, onDelete: 'SET NULL')]
    private ?InternshipEvaluationPeriod $evaluationPeriod = null;

    #[ORM\Column(length: 20, enumType: ReminderBatchItemStatus::class)]
    private ReminderBatchItemStatus $status;

    #[ORM\Column(name: 'exclusion_reason', length: 30, nullable: true, enumType: ReminderBatchExclusion::class)]
    private ?ReminderBatchExclusion $exclusionReason = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $attempts = 0;

    #[ORM\Column(name: 'claimed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    #[ORM\Column(name: 'settled_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $settledAt = null;

    // The address actually written to, as read just before the mail.
    #[ORM\Column(name: 'recipient_email', length: 180, nullable: true)]
    private ?string $recipientEmail = null;

    #[ORM\Column(name: 'copied_secondary_tutor')]
    private bool $copiedSecondaryTutor = false;

    // SES's identifier for the mail - what delivery events would name, if the main account ever
    // sends them (lot 4 of the spec, not built).
    #[ORM\Column(name: 'provider_message_id', length: 120, nullable: true)]
    private ?string $providerMessageId = null;

    #[ORM\Column(name: 'last_error', length: 255, nullable: true)]
    private ?string $lastError = null;

    #[ORM\OneToOne(targetEntity: InternshipReminder::class)]
    #[ORM\JoinColumn(name: 'reminder_id', nullable: true, onDelete: 'SET NULL')]
    private ?InternshipReminder $reminder = null;

    public function __construct(InternshipReminderBatch $batch, InternshipTutorLink $tutorLink, Program $program, ?InternshipEvaluationPeriod $evaluationPeriod, ?ReminderBatchExclusion $exclusion)
    {
        $this->batch = $batch;
        $this->tutorLink = $tutorLink;
        $this->program = $program;
        $this->evaluationPeriod = $evaluationPeriod;
        $this->exclusionReason = $exclusion;
        $this->status = null === $exclusion ? ReminderBatchItemStatus::Pending : ReminderBatchItemStatus::Excluded;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBatch(): ?InternshipReminderBatch
    {
        return $this->batch;
    }

    public function getTutorLink(): ?InternshipTutorLink
    {
        return $this->tutorLink;
    }

    public function getProgram(): ?Program
    {
        return $this->program;
    }

    public function getEvaluationPeriod(): ?InternshipEvaluationPeriod
    {
        return $this->evaluationPeriod;
    }

    public function getStatus(): ReminderBatchItemStatus
    {
        return $this->status;
    }

    public function getExclusionReason(): ?ReminderBatchExclusion
    {
        return $this->exclusionReason;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getClaimedAt(): ?\DateTimeImmutable
    {
        return $this->claimedAt;
    }

    public function getSettledAt(): ?\DateTimeImmutable
    {
        return $this->settledAt;
    }

    public function getRecipientEmail(): ?string
    {
        return $this->recipientEmail;
    }

    public function hasCopiedSecondaryTutor(): bool
    {
        return $this->copiedSecondaryTutor;
    }

    public function getProviderMessageId(): ?string
    {
        return $this->providerMessageId;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getReminder(): ?InternshipReminder
    {
        return $this->reminder;
    }

    /** « En envoi », written before SES is called - the half of « exactly once » a dead pass leaves behind. */
    public function claim(\DateTimeImmutable $at): void
    {
        $this->status = ReminderBatchItemStatus::Sending;
        $this->claimedAt = $at;
        ++$this->attempts;
    }

    public function markSent(InternshipReminder $reminder, string $recipientEmail, bool $copiedSecondaryTutor, ?string $providerMessageId, \DateTimeImmutable $at): void
    {
        $this->status = ReminderBatchItemStatus::Sent;
        $this->reminder = $reminder;
        $this->recipientEmail = $recipientEmail;
        $this->copiedSecondaryTutor = $copiedSecondaryTutor;
        $this->providerMessageId = $providerMessageId;
        $this->lastError = null;
        $this->settledAt = $at;
    }

    public function settleWithoutMail(ReminderBatchItemStatus $status, \DateTimeImmutable $at): void
    {
        if (!\in_array($status, [ReminderBatchItemStatus::AlreadyDone, ReminderBatchItemStatus::NotConcerned, ReminderBatchItemStatus::Cancelled, ReminderBatchItemStatus::Uncertain], true)) {
            throw new \LogicException(\sprintf('"%s" is not a way for a line to end without a mail.', $status->value));
        }

        $this->status = $status;
        $this->settledAt = $at;
    }

    /**
     * A passing error (throttling, SES unreachable): tried again at the next pass, until the last
     * attempt, which fails the line.
     */
    public function deferAfterError(string $error, \DateTimeImmutable $at): void
    {
        $this->lastError = mb_substr($error, 0, 255);

        if ($this->attempts >= self::MAX_ATTEMPTS) {
            $this->status = ReminderBatchItemStatus::Failed;
            $this->settledAt = $at;

            return;
        }

        $this->status = ReminderBatchItemStatus::Pending;
    }

    public function fail(string $error, ?string $recipientEmail, \DateTimeImmutable $at): void
    {
        $this->status = ReminderBatchItemStatus::Failed;
        $this->lastError = mb_substr($error, 0, 255);
        $this->recipientEmail = $recipientEmail;
        $this->settledAt = $at;
    }
}
