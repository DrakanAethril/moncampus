<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The finish of a collection piece (design/validated/serious-games.md §8, « Récompenses »). It
 * only ever goes up: rank() is what « monter » compares.
 */
enum SeriousGameFinish: string
{
    case Brass = 'brass';
    case Silver = 'silver';
    case Gold = 'gold';

    public function rank(): int
    {
        return match ($this) {
            self::Brass => 1,
            self::Silver => 2,
            self::Gold => 3,
        };
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Brass => 'seriousGameFinishBrass',
            self::Silver => 'seriousGameFinishSilver',
            self::Gold => 'seriousGameFinishGold',
        };
    }
}
