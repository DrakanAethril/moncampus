<?php

namespace App\Enum;

/** Whether a ProgramFinancialItem's total counts as a gain or a cost in the program's reporting. */
enum FinancialItemType: string
{
    case Gain = 'gain';
    case Cost = 'cost';

    public function labelKey(): string
    {
        return match ($this) {
            self::Gain => 'financialItemTypeGainLabel',
            self::Cost => 'financialItemTypeCostLabel',
        };
    }
}
