<?php

namespace App\Enum;

// See design/design_campus_manager/README.md "e-CO" section and reference/e-CO.dc.html screen 1g.
enum EcoCourseMode: string
{
    case ImposedOrder = 'imposed_order';
    case FreeOrder = 'free_order';
    case Score = 'score';

    public function labelKey(): string
    {
        return match ($this) {
            self::ImposedOrder => 'ecoCourseModeImposedOrderLabel',
            self::FreeOrder => 'ecoCourseModeFreeOrderLabel',
            self::Score => 'ecoCourseModeScoreLabel',
        };
    }

    public function descriptionKey(): string
    {
        return match ($this) {
            self::ImposedOrder => 'ecoCourseModeImposedOrderDescription',
            self::FreeOrder => 'ecoCourseModeFreeOrderDescription',
            self::Score => 'ecoCourseModeScoreDescription',
        };
    }
}
