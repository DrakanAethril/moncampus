<?php

namespace App\Enum;

/**
 * Which signature a logged InternshipReminder chased - the 3 Engagement steps (no
 * InternshipEvaluationPeriod involved) plus the 4 per-period roles. Drives both the reminder
 * template's merge copy and AlternancePeriodStatusResolver's "whose turn is it" labelling.
 */
enum AlternanceReminderStep: string
{
    case EngagementTutor = 'engagement_tutor';
    case EngagementStudent = 'engagement_student';
    case EngagementCenter = 'engagement_center';
    case Tutor = 'tutor';
    case Student = 'student';
    case Team = 'team';
    case Supervisor = 'supervisor';

    public function roleLabelKey(): string
    {
        return match ($this) {
            self::EngagementTutor, self::Tutor => 'ufaAlternanceRoleTutorLabel',
            self::EngagementStudent, self::Student => 'ufaAlternanceRoleStudentLabel',
            self::EngagementCenter => 'ufaAlternanceRoleCenterLabel',
            self::Team => 'ufaAlternanceRoleTeamLabel',
            self::Supervisor => 'ufaAlternanceRoleSupervisorLabel',
        };
    }
}
