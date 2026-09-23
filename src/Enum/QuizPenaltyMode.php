<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How much a wrong answer costs, when « note négative sur erreurs » is on at launch (screen 1c).
 *
 * The two modes answer the same question in the two units a teacher thinks in: a flat number of
 * points, or a share of what the question itself is worth. They are never combined - the mode is
 * the choice, and App\Entity\QuizInstance::penaltyFor() is the only place that reads it.
 */
enum QuizPenaltyMode: string
{
    case Fixed = 'fixed';
    case Scale = 'scale';

    public function labelKey(): string
    {
        return match ($this) {
            self::Fixed => 'quizPenaltyModeFixedLabel',
            self::Scale => 'quizPenaltyModeScaleLabel',
        };
    }
}
