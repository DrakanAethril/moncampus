<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AlternanceReminderStep;
use App\Repository\InternshipReminderRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A logged send of one alternance's follow-up reminder (single, from the 34c panel, or bulk, from
 * the 26i grouped-relances screen) - "Internship"-prefixed to avoid a bare, collision-prone
 * "Reminder" class name. $auto exists for a future automatic/cron-triggered reminder but is always
 * false today: every send in this app is staff-triggered by a button click, there is no scheduled
 * task that sets it (see AlternanceReminderService).
 */
#[ORM\Entity(repositoryClass: InternshipReminderRepository::class)]
#[ORM\Table(name: 'internship_reminder')]
class InternshipReminder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: InternshipTutorLink::class)]
    #[ORM\JoinColumn(name: 'tutor_link_id', nullable: false)]
    private ?InternshipTutorLink $tutorLink = null;

    // Null for the 3 Engagement* steps, which aren't scoped to a period.
    #[ORM\ManyToOne(targetEntity: InternshipEvaluationPeriod::class)]
    #[ORM\JoinColumn(name: 'evaluation_period_id', nullable: true)]
    private ?InternshipEvaluationPeriod $evaluationPeriod = null;

    #[ORM\Column(length: 30, enumType: AlternanceReminderStep::class)]
    private AlternanceReminderStep $step;

    #[ORM\Column(name: 'sent_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $sentAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'sent_by_id', nullable: false)]
    private ?User $sentBy = null;

    #[ORM\Column]
    private bool $auto = false;

    public function __construct(InternshipTutorLink $tutorLink, AlternanceReminderStep $step, User $sentBy, ?InternshipEvaluationPeriod $evaluationPeriod = null)
    {
        $this->tutorLink = $tutorLink;
        $this->step = $step;
        $this->sentBy = $sentBy;
        $this->evaluationPeriod = $evaluationPeriod;
        $this->sentAt = new \DateTimeImmutable();
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

    public function getStep(): AlternanceReminderStep
    {
        return $this->step;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getSentBy(): ?User
    {
        return $this->sentBy;
    }

    public function isAuto(): bool
    {
        return $this->auto;
    }
}
