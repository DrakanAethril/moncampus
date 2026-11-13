<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How a CRM candidate is to follow the formation. The CRM's own values, not Formatool's
 * `Modality` structure node: they decide which documents a dossier asks for, and a candidate
 * moves between them while the dossier is under way (« Changer de modalité »). The order of the
 * cases is the order of every list, column and checkbox row of the CRM.
 */
enum CrmModality: string implements HasBadge
{
    case Initial = 'initial';
    case School = 'school';
    case Apprenticeship = 'apprenticeship';
    case ProfessionalContract = 'professional';
    case Other = 'other';

    public function labelKey(): string
    {
        return match ($this) {
            self::Initial => 'crmModalityInitialLabel',
            self::School => 'crmModalitySchoolLabel',
            self::Apprenticeship => 'crmModalityApprenticeshipLabel',
            self::ProfessionalContract => 'crmModalityProfessionalContractLabel',
            self::Other => 'crmModalityOtherLabel',
        };
    }

    /** The lower-case short form the key figures are captioned with (« apprentissage / initiale »). */
    public function shortLabelKey(): string
    {
        return match ($this) {
            self::Initial => 'crmModalityInitialShortLabel',
            self::School => 'crmModalitySchoolShortLabel',
            self::Apprenticeship => 'crmModalityApprenticeshipShortLabel',
            self::ProfessionalContract => 'crmModalityProfessionalContractShortLabel',
            self::Other => 'crmModalityOtherShortLabel',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Initial => BadgeTone::Blue,
            self::School => BadgeTone::Teal,
            self::Apprenticeship => BadgeTone::Gold,
            self::ProfessionalContract => BadgeTone::Green,
            self::Other => BadgeTone::Purple,
        };
    }

    /** The French label, for the journal and the connector, which write text rather than keys. */
    public function label(): string
    {
        return match ($this) {
            self::Initial => 'Formation initiale',
            self::School => 'Scolaire',
            self::Apprenticeship => 'Apprentissage',
            self::ProfessionalContract => 'Contrat pro',
            self::Other => 'Autre',
        };
    }

    /**
     * The values the connector's tools accept, for their schemas and their refusals.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $m): string => $m->value, self::cases());
    }
}
