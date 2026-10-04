<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The two boxes under every evaluation table of the ECF: the candidate is considered to have met
 * the référentiel's criteria for the activity-type, or not.
 */
enum EcfResult: string implements HasBadge
{
    case Satisfied = 'satisfied';
    case NotSatisfied = 'not_satisfied';

    public function labelKey(): string
    {
        return match ($this) {
            self::Satisfied => 'ecfResultSatisfiedLabel',
            self::NotSatisfied => 'ecfResultNotSatisfiedLabel',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Satisfied => BadgeTone::Green,
            self::NotSatisfied => BadgeTone::Gold,
        };
    }
}
