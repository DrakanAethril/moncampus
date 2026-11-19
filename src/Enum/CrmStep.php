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
    case FileSending = 'file_sending';
    case Enrolment = 'enrolment';
    case DiPieces = 'di_pieces';
    case Documents = 'documents';
    case Complete = 'complete';

    public function labelKey(): string
    {
        return match ($this) {
            self::Modality => 'crmStepModalityLabel',
            self::PreEnrolment => 'crmPhasePreEnrolmentLabel',
            self::FileSending => 'crmPhaseFileSendingLabel',
            self::Enrolment => 'crmPhaseEnrolmentLabel',
            self::DiPieces => 'crmPhaseDiPiecesLabel',
            self::Documents => 'crmPhaseDocumentsLabel',
            self::Complete => 'crmStepCompleteLabel',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Modality => BadgeTone::Gray,
            self::PreEnrolment => BadgeTone::Gold,
            self::FileSending => BadgeTone::Teal,
            self::Enrolment => BadgeTone::Blue,
            self::DiPieces => BadgeTone::Purple,
            self::Documents => BadgeTone::Purple,
            self::Complete => BadgeTone::Green,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Modality => 'Modalité à renseigner',
            self::PreEnrolment => 'Pré-inscription',
            self::FileSending => 'Envoi du dossier',
            self::Enrolment => 'Inscription',
            self::DiPieces => 'Pièces D.I.',
            self::Documents => 'Documents d’entrée',
            self::Complete => 'Complet',
        };
    }
}
