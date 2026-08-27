<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Whether teams are redrawn at each period or kept for the year (§4, decision 7).
 *
 * Redistribution is the default: a house gains a lasting identity and loses the mixing. Both stay
 * **inside the program** - cross-filière houses are ruled out because their members share neither
 * lessons, nor deadlines, nor teachers, so none of them could help another, which is the only thing
 * the collective threshold exists to produce.
 */
enum GameTeamMode: string
{
    case Period = 'period';
    case House = 'house';

    public function labelKey(): string
    {
        return match ($this) {
            self::Period => 'gameTeamModePeriodLabel',
            self::House => 'gameTeamModeHouseLabel',
        };
    }
}
