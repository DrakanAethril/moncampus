<?php

declare(strict_types=1);

namespace App\Enum;

// The "Placée / Partiellement placée / Non placée" chip of screen 5a. Derived, never persisted -
// see App\Entity\ProgressionSequence::getStatus().
enum ProgressionSequenceStatus: string
{
    case Placed = 'placed';
    case PartiallyPlaced = 'partially_placed';
    case NotPlaced = 'not_placed';

    public function labelKey(): string
    {
        return match ($this) {
            self::Placed => 'progressionSequenceStatusPlacedLabel',
            self::PartiallyPlaced => 'progressionSequenceStatusPartiallyPlacedLabel',
            self::NotPlaced => 'progressionSequenceStatusNotPlacedLabel',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Placed => 'ok',
            self::PartiallyPlaced => 'warn',
            self::NotPlaced => 'danger',
        };
    }
}
