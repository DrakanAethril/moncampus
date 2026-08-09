<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Ce qu'une ligne de App\Entity\UfaActivity raconte. Ajouter un suivi tient en trois gestes et
 * aucune migration (la colonne est un varchar, pas un enum SQL) : un case ici, sa clé de
 * traduction dans messageKey(), et un appel à App\Service\UfaActivityRecorder au point d'action.
 *
 * Les libellés sont des clés à placeholders et non des phrases toutes faites : le journal stocke
 * un type et un instantané de noms (UfaActivity::$payload), la phrase se compose à l'affichage.
 * Reformuler n'a donc jamais à réécrire l'historique.
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
     * Les placeholders disponibles sont ceux que le recorder met dans le payload : %student%,
     * %tutor%, %actor%, %period%, %role%.
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
