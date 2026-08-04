<?php

namespace App\Enum;

/**
 * Pourquoi une adresse a été placée sur la liste de suppression locale : on n'écrit plus à une
 * adresse morte, ce qui protège la réputation du domaine `etu.beaupeyrat.org` auprès des
 * fournisseurs de messagerie.
 *
 * Liste *locale*, distincte de celle que SES tient au niveau du compte : celle-ci bloque l'envoi
 * en amont et permet d'expliquer à l'élève pourquoi un contact est barré dans son vivier.
 */
enum EmailSuppressionReason: string
{
    case HardBounce = 'hard_bounce';
    case Complaint = 'complaint';
    case Manual = 'manual';

    public function labelKey(): string
    {
        return match ($this) {
            self::HardBounce => 'emailSuppressionHardBounceLabel',
            self::Complaint => 'emailSuppressionComplaintLabel',
            self::Manual => 'emailSuppressionManualLabel',
        };
    }
}
