<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The two things a validateur can say about a dépôt.
 *
 * A correction request **must** carry a comment - the rule is in App\Entity\DossierReview rather
 * than only in the form, because a correction with nothing to correct is a refusal the cible cannot
 * act on. A validation may carry one and usually does not.
 */
enum DossierReviewAction: string
{
    case Validated = 'validation';
    case CorrectionRequested = 'demande_correction';

    public function labelKey(): string
    {
        return match ($this) {
            self::Validated => 'dossierReviewValidatedLabel',
            self::CorrectionRequested => 'dossierReviewCorrectionRequestedLabel',
        };
    }

    public function requiresComment(): bool
    {
        return self::CorrectionRequested === $this;
    }
}
