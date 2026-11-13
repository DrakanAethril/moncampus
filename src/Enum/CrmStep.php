<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where a dossier is: the first stage whose required pieces are not all done, or « Complet ». The
 * « Documents de suivi » are no stage: they never hold a dossier back. A dossier whose modality is
 * « Non renseignée » is asked nothing and stands at « Modalité à renseigner », before every stage.
 * Read, never stored (App\Service\Crm\CrmDossierProgress).
 */
enum CrmStep: string implements HasBadge
{
    case Modality = 'modality';
    case PreEnrolment = 'pre_enrolment';
    case Enrolment = 'enrolment';
    case Documents = 'documents';
    case Complete = 'complete';

    public function labelKey(): string
    {
        return match ($this) {
            self::Modality => 'crmStepModalityLabel',
            self::PreEnrolment => 'crmPhasePreEnrolmentLabel',
            self::Enrolment => 'crmPhaseEnrolmentLabel',
            self::Documents => 'crmPhaseDocumentsLabel',
            self::Complete => 'crmStepCompleteLabel',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Modality => BadgeTone::Gray,
            self::PreEnrolment => BadgeTone::Gold,
            self::Enrolment => BadgeTone::Blue,
            self::Documents => BadgeTone::Purple,
            self::Complete => BadgeTone::Green,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Modality => 'Modalité à renseigner',
            self::PreEnrolment => 'Pré-inscription',
            self::Enrolment => 'Inscription',
            self::Documents => 'Documents d’entrée',
            self::Complete => 'Complet',
        };
    }
}
