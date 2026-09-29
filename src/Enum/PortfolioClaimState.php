<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * One competency a student claims on a réalisation, and what the validateur made of it.
 *
 * The synthesis table ticks a box only for a **retained** claim on a **validated** réalisation - a
 * claim waiting for its decision shows on screen as a hollow circle and is never exported.
 */
enum PortfolioClaimState: string implements HasBadge
{
    case Claimed = 'claimed';
    case Retained = 'retained';
    case NotRetained = 'not_retained';

    public function labelKey(): string
    {
        return match ($this) {
            self::Claimed => 'portfolioClaimClaimedLabel',
            self::Retained => 'portfolioClaimRetainedLabel',
            self::NotRetained => 'portfolioClaimNotRetainedLabel',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Claimed => BadgeTone::Gold,
            self::Retained => BadgeTone::Green,
            self::NotRetained => BadgeTone::Gray,
        };
    }
}
