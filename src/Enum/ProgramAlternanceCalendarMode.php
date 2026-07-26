<?php

namespace App\Enum;

/**
 * Whether a Program's alternance calendar nav entry generates the existing PeriodGroup-derived
 * PDF (App\Controller\ProgramController::alternanceCalendarPdf(), already priority-aware across
 * a Program's attached PeriodGroups) or serves an uploaded PDF instead
 * (Program::$alternanceCalendarFileKey) - same route either way, the controller branches on this
 * value.
 */
enum ProgramAlternanceCalendarMode: string
{
    case Period = 'period';
    case File = 'file';

    public function labelKey(): string
    {
        return match ($this) {
            self::Period => 'programAlternanceCalendarModePeriodLabel',
            self::File => 'programAlternanceCalendarModeFileLabel',
        };
    }
}
