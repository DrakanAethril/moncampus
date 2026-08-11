<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How far from the expected value a numeric answer may land - see
 * App\Entity\QuizQuestionDefinitionTrait's $numericConfig.
 *
 * Percent is the default because it is what a marking scheme usually says ("à 2 % près") and
 * because it travels: the same question asked with different drawn values keeps the same fairness.
 * Absolute exists for the cases where a percentage is meaningless - a temperature in °C, a result
 * that legitimately crosses zero, a count of objects.
 */
enum ToleranceMode: string
{
    case Percent = 'percent';
    case Absolute = 'absolute';

    public function labelKey(): string
    {
        return match ($this) {
            self::Percent => 'toleranceModePercentLabel',
            self::Absolute => 'toleranceModeAbsoluteLabel',
        };
    }

    public function suffix(): string
    {
        return match ($this) {
            self::Percent => '%',
            self::Absolute => '±',
        };
    }
}
