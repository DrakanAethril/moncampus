<?php

declare(strict_types=1);

namespace App\Enum;

use App\Service\AlternanceStepStatus;

/**
 * The stage of the livret a reminder batch chases - one per batch (design/validated/
 * ufa-reminder-batches.md). The two engagement signatures, and the tutor's and the alternant's
 * evaluation of a bilan, the bilan itself being named by its rank (ReminderBatchTarget).
 *
 * Neither the centre's signature nor the supervisor's closing is here: the first has no recipient,
 * the second was left out of the first version (Q3) - AlternanceReminderStep already names it, so
 * adding it is a case here and a line in the board.
 */
enum ReminderBatchStep: string
{
    case EngagementTutor = 'engagement_tutor';
    case EngagementStudent = 'engagement_student';
    case PeriodTutor = 'period_tutor';
    case PeriodStudent = 'period_student';

    public static function fromStatusStep(string $statusStep): ?self
    {
        return match ($statusStep) {
            AlternanceStepStatus::STEP_ENGAGEMENT_TUTOR => self::EngagementTutor,
            AlternanceStepStatus::STEP_ENGAGEMENT_STUDENT => self::EngagementStudent,
            AlternanceStepStatus::STEP_TUTOR => self::PeriodTutor,
            AlternanceStepStatus::STEP_STUDENT => self::PeriodStudent,
            default => null,
        };
    }

    /** The resolver's name for the same step - what « is it still this person's turn » compares. */
    public function statusStep(): string
    {
        return match ($this) {
            self::EngagementTutor => AlternanceStepStatus::STEP_ENGAGEMENT_TUTOR,
            self::EngagementStudent => AlternanceStepStatus::STEP_ENGAGEMENT_STUDENT,
            self::PeriodTutor => AlternanceStepStatus::STEP_TUTOR,
            self::PeriodStudent => AlternanceStepStatus::STEP_STUDENT,
        };
    }

    /** What the logged InternshipReminder says it chased. */
    public function reminderStep(): AlternanceReminderStep
    {
        return match ($this) {
            self::EngagementTutor => AlternanceReminderStep::EngagementTutor,
            self::EngagementStudent => AlternanceReminderStep::EngagementStudent,
            self::PeriodTutor => AlternanceReminderStep::Tutor,
            self::PeriodStudent => AlternanceReminderStep::Student,
        };
    }

    public function isPeriod(): bool
    {
        return self::PeriodTutor === $this || self::PeriodStudent === $this;
    }

    /** Takes %rank% for the two bilan steps. */
    public function labelKey(): string
    {
        return match ($this) {
            self::EngagementTutor => 'reminderBatchStepEngagementTutorLabel',
            self::EngagementStudent => 'reminderBatchStepEngagementStudentLabel',
            self::PeriodTutor => 'reminderBatchStepPeriodTutorLabel',
            self::PeriodStudent => 'reminderBatchStepPeriodStudentLabel',
        };
    }

    /** The short form the breadcrumb and the lists use (« Bilan 2 · tuteurs »). */
    public function shortLabelKey(): string
    {
        return match ($this) {
            self::EngagementTutor => 'reminderBatchStepEngagementTutorShortLabel',
            self::EngagementStudent => 'reminderBatchStepEngagementStudentShortLabel',
            self::PeriodTutor => 'reminderBatchStepPeriodTutorShortLabel',
            self::PeriodStudent => 'reminderBatchStepPeriodStudentShortLabel',
        };
    }
}
