<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a SIRET candidate has in common with the employer it is proposed for - shown as a badge
 * beside the candidate, **never acted upon** (design/validated/siret-entreprises.md, R2): the
 * measure of 2026-09-29 found every « adresse identique » right, and it still takes a person's
 * click to associate one.
 */
enum SiretEvidence: string implements HasBadge
{
    /** Street number and street name both match. */
    case SameAddress = 'same_address';

    /** The street matches, the number does not (or is missing on one side). */
    case SameStreet = 'same_street';

    /** Only the postcode matches. */
    case SamePostalCode = 'same_postal_code';

    /** Another candidate of the same company is listed, at another address. */
    case SameCompanyElsewhere = 'same_company_elsewhere';

    public function labelKey(): string
    {
        return match ($this) {
            self::SameAddress => 'siretEvidenceSameAddressLabel',
            self::SameStreet => 'siretEvidenceSameStreetLabel',
            self::SamePostalCode => 'siretEvidenceSamePostalCodeLabel',
            self::SameCompanyElsewhere => 'siretEvidenceSameCompanyElsewhereLabel',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::SameAddress => BadgeTone::Green,
            self::SameStreet => BadgeTone::Teal,
            self::SamePostalCode => BadgeTone::Blue,
            self::SameCompanyElsewhere => BadgeTone::Gray,
        };
    }
}
