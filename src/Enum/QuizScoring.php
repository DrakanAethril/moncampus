<?php

declare(strict_types=1);

namespace App\Enum;

// Scale chosen at launch (screen 1c) - deliberately unrelated to any carnet de notes, see
// design/design_campus_manager/README.md's "Générateur de quiz" section.
enum QuizScoring: string
{
    case Note20 = 'note20';
    case ScorePercent = 'score_percent';

    public function labelKey(): string
    {
        return match ($this) {
            self::Note20 => 'quizScoringNote20Label',
            self::ScorePercent => 'quizScoringScorePercentLabel',
        };
    }
}
