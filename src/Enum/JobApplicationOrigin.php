<?php

namespace App\Enum;

/**
 * Where an application came from (design_handoff_stage_alternance, screens 2a and 2b:
 * "speculative", "via an imported offer", "phone call added by hand").
 *
 * Purely descriptive: the handoff forbids any sorting or analysis of replies, so this enum says
 * where the application started, never where it stands.
 */
enum JobApplicationOrigin: string
{
    case Spontaneous = 'spontaneous';
    case Offer = 'offer';

    /** An application entered by the team outside any mail (phone interview, careers fair). */
    case Manual = 'manual';

    public function labelKey(): string
    {
        return match ($this) {
            self::Spontaneous => 'jobApplicationOriginSpontaneousLabel',
            self::Offer => 'jobApplicationOriginOfferLabel',
            self::Manual => 'jobApplicationOriginManualLabel',
        };
    }
}
