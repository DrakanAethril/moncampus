<?php

namespace App\Enum;

enum EcoScanResult: string
{
    case Success = 'success';
    case OutOfRange = 'out_of_range';
    case OutOfOrder = 'out_of_order';

    public function labelKey(): string
    {
        return match ($this) {
            self::Success => 'ecoScanResultSuccessLabel',
            self::OutOfRange => 'ecoScanResultOutOfRangeLabel',
            self::OutOfOrder => 'ecoScanResultOutOfOrderLabel',
        };
    }
}
