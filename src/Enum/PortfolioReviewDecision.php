<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a validateur decides about one revision of a réalisation or of an E6 fiche.
 *
 * Sending back must say why (R6): the rule lives in App\Entity\PortfolioReview's constructor, so a
 * decision with nothing to act on cannot be written by any caller.
 */
enum PortfolioReviewDecision: string
{
    case Validated = 'validated';
    case ToRework = 'to_rework';

    public function labelKey(): string
    {
        return match ($this) {
            self::Validated => 'portfolioReviewValidatedLabel',
            self::ToRework => 'portfolioReviewToReworkLabel',
        };
    }

    public function requiresComment(): bool
    {
        return self::ToRework === $this;
    }
}
