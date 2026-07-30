<?php

namespace App\Enum;

// The zoom level a teacher last picked when building a progression (screen 3c, step 3). Purely a
// display preference - "le pas se change à tout moment, c'est un zoom, pas un engagement"
// (design/design_handoff_progression/README.md §3) - so nothing in
// App\Service\ProgressionPlacementService ever reads it.
enum ProgressionDisplayStep: string
{
    case Month = 'month';
    case Week = 'week';
    case Seance = 'seance';

    public function labelKey(): string
    {
        return match ($this) {
            self::Month => 'progressionStepMonthLabel',
            self::Week => 'progressionStepWeekLabel',
            self::Seance => 'progressionStepSeanceLabel',
        };
    }

    public function hintKey(): string
    {
        return match ($this) {
            self::Month => 'progressionStepMonthHint',
            self::Week => 'progressionStepWeekHint',
            self::Seance => 'progressionStepSeanceHint',
        };
    }
}
