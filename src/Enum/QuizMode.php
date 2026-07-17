<?php

namespace App\Enum;

// How a launched QuizInstance behaves - see design/design_campus_manager/reference/Générateur de
// quiz.dc.html, screen 1c. Live is part of the strict data model (multi-player "concours" mode)
// but deliberately unexposed in the launch UI for now - see QuizLaunchType's docblock - so no
// screen ever lets a teacher actually select it yet.
enum QuizMode: string
{
    case Entrainement = 'entrainement';
    case Evaluation = 'evaluation';
    case Live = 'live';

    public function labelKey(): string
    {
        return match ($this) {
            self::Entrainement => 'quizModeEntrainementLabel',
            self::Evaluation => 'quizModeEvaluationLabel',
            self::Live => 'quizModeLiveLabel',
        };
    }

    public function descriptionKey(): string
    {
        return match ($this) {
            self::Entrainement => 'quizModeEntrainementDescription',
            self::Evaluation => 'quizModeEvaluationDescription',
            self::Live => 'quizModeLiveDescription',
        };
    }
}
