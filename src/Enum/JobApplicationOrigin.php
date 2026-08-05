<?php

namespace App\Enum;

/**
 * D'où vient une démarche de candidature (design_handoff_stage_alternance, écrans 2a et 2b :
 * « spontanée », « via offre importée », « échange téléphonique ajouté à la main »).
 *
 * Purement descriptif : le handoff interdit tout classement ou analyse des réponses, donc cette
 * énumération dit d'où part la démarche, jamais où elle en est.
 */
enum JobApplicationOrigin: string
{
    case Spontaneous = 'spontaneous';
    case Offer = 'offer';

    /** Démarche saisie par l'équipe hors de tout mail (entretien téléphonique, forum). */
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
