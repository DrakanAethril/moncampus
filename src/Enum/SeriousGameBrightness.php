<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How bright a lit unit shines, read on its best series out of 12 (design/validated/serious-games.md
 * §1.4): discrète 6 or 7, nette 8 or 9, brillante 10 or 11, éclatante 12. Below 6 the unit is not
 * lit, which is `fromBest()` answering null.
 *
 * It is never stored: `SeriousGameUnitState::$best` is, and it only goes up, so the brightness
 * cannot go down either - neither with time nor with a worse series.
 */
enum SeriousGameBrightness: string implements HasBadge
{
    case Discrete = 'discrete';
    case Clear = 'clear';
    case Bright = 'bright';
    case Blazing = 'blazing';

    /** The best series a unit needs to be lit at all. */
    public const int LIT_AT = 6;

    public static function fromBest(?int $best): ?self
    {
        return match (true) {
            null === $best, $best < self::LIT_AT => null,
            $best >= 12 => self::Blazing,
            $best >= 10 => self::Bright,
            $best >= 8 => self::Clear,
            default => self::Discrete,
        };
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Discrete => 'seriousGameBrightnessDiscrete',
            self::Clear => 'seriousGameBrightnessClear',
            self::Bright => 'seriousGameBrightnessBright',
            self::Blazing => 'seriousGameBrightnessBlazing',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Discrete => BadgeTone::Gray,
            self::Clear => BadgeTone::Blue,
            self::Bright => BadgeTone::Gold,
            self::Blazing => BadgeTone::Green,
        };
    }
}
