<?php

namespace App\Enum;

// Optional per-question difficulty. Null (not this enum) is how "unset" is represented on
// QuizQuestion::$difficulty - every place that reads it (difficulty-slider draw, the ●●○ summary
// on 1a/1b) treats a null the same as self::Moyen, per design/design_campus_manager/README.md's
// "Générateur de quiz" section.
enum QuestionDifficulty: string
{
    case Facile = 'facile';
    case Moyen = 'moyen';
    case Difficile = 'difficile';

    public function labelKey(): string
    {
        return match ($this) {
            self::Facile => 'questionDifficultyFacileLabel',
            self::Moyen => 'questionDifficultyMoyenLabel',
            self::Difficile => 'questionDifficultyDifficileLabel',
        };
    }

    // 1-3 "dots" filled in the ●●○-style indicator (1a/1b) - Facile=1, Moyen=2, Difficile=3.
    public function dotCount(): int
    {
        return match ($this) {
            self::Facile => 1,
            self::Moyen => 2,
            self::Difficile => 3,
        };
    }
}
