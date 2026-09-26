<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Why an incident happened - the second axis of the annual report: « 14 disparus, dont 9 vols ».
 *
 * What happened is the incident's kind (Disparu / Hors d'usage); this says why. Nothing here names
 * a person or a class, on purpose: the inventory measures losses, it does not impute them.
 */
enum EquipmentIncidentCause: string
{
    case Wear = 'wear';
    case Accident = 'accident';
    case Damage = 'damage';
    case Theft = 'theft';
    case Breakdown = 'breakdown';
    case Unknown = 'unknown';

    public function labelKey(): string
    {
        return match ($this) {
            self::Wear => 'equipmentCauseWearLabel',
            self::Accident => 'equipmentCauseAccidentLabel',
            self::Damage => 'equipmentCauseDamageLabel',
            self::Theft => 'equipmentCauseTheftLabel',
            self::Breakdown => 'equipmentCauseBreakdownLabel',
            self::Unknown => 'equipmentCauseUnknownLabel',
        };
    }
}
