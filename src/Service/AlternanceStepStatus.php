<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\User;

/**
 * One resolved "where is this alternance/period at" snapshot, returned by
 * AlternancePeriodStatusResolver - reused by the dashboard badge, the suivi page banner and
 * per-period rows, the relance panel, and the grouped relances screen, so all four screens agree
 * on the exact same notion of "whose turn it is" and "is it late".
 */
final readonly class AlternanceStepStatus
{
    public const STEP_ENGAGEMENT_TUTOR = 'engagement_tutor';
    public const STEP_ENGAGEMENT_STUDENT = 'engagement_student';
    public const STEP_ENGAGEMENT_CENTER = 'engagement_center';
    public const STEP_TUTOR = 'tutor';
    public const STEP_STUDENT = 'student';
    public const STEP_TEAM = 'team';
    public const STEP_SUPERVISOR = 'supervisor';
    public const STEP_CLOSED = 'closed';
    public const STEP_NOT_OPENED = 'not_opened';
    public const STEP_INACTIVE = 'inactive';

    public function __construct(
        public string $step,
        public bool $isLate,
        public ?User $pendingActor,
        public ?\DateTimeImmutable $dueDate,
        public ?InternshipEvaluationPeriod $period,
    ) {
    }

    public function isEngagementStep(): bool
    {
        return \in_array($this->step, [self::STEP_ENGAGEMENT_TUTOR, self::STEP_ENGAGEMENT_STUDENT, self::STEP_ENGAGEMENT_CENTER], true);
    }
}
