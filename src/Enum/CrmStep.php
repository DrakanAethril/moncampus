<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where a dossier is: the first stage whose required pieces are not all done, or « Complet ». The
 * « Documents de suivi » are no stage: they never hold a dossier back.
 * Read, never stored (App\Service\Crm\CrmDossierProgress).
 */
enum CrmStep: string implements HasBadge
{
    case PreEnrolment = 'pre_enrolment';
    case Enrolment = 'enrolment';
    case Documents = 'documents';
    case Complete = 'complete';

    public function labelKey(): string
    {
        return match ($this) {
            self::PreEnrolment => 'crmPhasePreEnrolmentLabel',
            self::Enrolment => 'crmPhaseEnrolmentLabel',
            self::Documents => 'crmPhaseDocumentsLabel',
            self::Complete => 'crmStepCompleteLabel',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::PreEnrolment => BadgeTone::Gold,
            self::Enrolment => BadgeTone::Blue,
            self::Documents => BadgeTone::Purple,
            self::Complete => BadgeTone::Green,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PreEnrolment => 'Pré-inscription',
            self::Enrolment => 'Inscription',
            self::Documents => 'Documents d’entrée',
            self::Complete => 'Complet',
        };
    }
}
