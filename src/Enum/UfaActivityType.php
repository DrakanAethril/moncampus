<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a row of App\Entity\UfaActivity tells. Adding a tracked action takes three gestures and no
 * migration (the column is a varchar, not an SQL enum): a case here, its translation key in
 * messageKey(), and a call to App\Service\UfaActivityRecorder at the point of action.
 *
 * The labels are keys with placeholders and not ready-made sentences: the log stores a type and a
 * snapshot of names (UfaActivity::$payload), the sentence is composed at display time. Rewording
 * therefore never has to rewrite the history.
 */
enum UfaActivityType: string
{
    case EngagementSignedTutor = 'engagement_signed_tutor';
    case EngagementSignedStudent = 'engagement_signed_student';
    case EngagementSignedCenter = 'engagement_signed_center';
    case PeriodTutorSigned = 'period_tutor_signed';
    case PeriodStudentSigned = 'period_student_signed';
    case PeriodTeamSigned = 'period_team_signed';
    case PeriodSupervisorClosed = 'period_supervisor_closed';
    case ReminderSent = 'reminder_sent';

    /**
     * The placeholders available are those the recorder puts in the payload: %student%, %tutor%,
     * %actor%, %period%, %role%.
     */
    public function messageKey(): string
    {
        return match ($this) {
            self::EngagementSignedTutor => 'ufaActivityEngagementSignedTutorText',
            self::EngagementSignedStudent => 'ufaActivityEngagementSignedStudentText',
            self::EngagementSignedCenter => 'ufaActivityEngagementSignedCenterText',
            self::PeriodTutorSigned => 'ufaActivityPeriodTutorSignedText',
            self::PeriodStudentSigned => 'ufaActivityPeriodStudentSignedText',
            self::PeriodTeamSigned => 'ufaActivityPeriodTeamSignedText',
            self::PeriodSupervisorClosed => 'ufaActivityPeriodSupervisorClosedText',
            self::ReminderSent => 'ufaActivityReminderSentText',
        };
    }
}
