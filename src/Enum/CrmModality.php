<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How a CRM candidate is to follow the formation. The CRM's own three values, not Formatool's
 * `Modality` structure node: they decide which documents a dossier asks for, and a candidate
 * moves between them while the dossier is under way (« Changer de modalité »).
 */
enum CrmModality: string implements HasBadge
{
    case Initial = 'initial';
    case Apprenticeship = 'apprenticeship';
    case Other = 'other';

    public function labelKey(): string
    {
        return match ($this) {
            self::Initial => 'crmModalityInitialLabel',
            self::Apprenticeship => 'crmModalityApprenticeshipLabel',
            self::Other => 'crmModalityOtherLabel',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Initial => BadgeTone::Blue,
            self::Apprenticeship => BadgeTone::Gold,
            self::Other => BadgeTone::Purple,
        };
    }

    /** The French label, for the journal and the connector, which write text rather than keys. */
    public function label(): string
    {
        return match ($this) {
            self::Initial => 'Formation initiale',
            self::Apprenticeship => 'Apprentissage',
            self::Other => 'Autre',
        };
    }
}
