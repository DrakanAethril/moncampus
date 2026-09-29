<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * A deposit is frozen the moment it is made; only its conformity check and its endorsement move.
 */
enum PortfolioDepositState: string implements HasBadge
{
    case Deposited = 'deposited';
    case Endorsed = 'endorsed';
    case ToRegularise = 'to_regularise';

    public function labelKey(): string
    {
        return match ($this) {
            self::Deposited => 'portfolioDepositDepositedLabel',
            self::Endorsed => 'portfolioDepositEndorsedLabel',
            self::ToRegularise => 'portfolioDepositToRegulariseLabel',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Deposited => BadgeTone::Gold,
            self::Endorsed => BadgeTone::Green,
            self::ToRegularise => BadgeTone::Red,
        };
    }
}
