<?php

declare(strict_types=1);

namespace App\Enum;

// How a launched QuizInstance behaves - see design/design_campus_manager/reference/Générateur de
// quiz.dc.html, screen 1c. Live is never chosen in the launch form: a contest is created by its
// own screen (Outils > Concours live, App\Controller\QuizLiveHostController), which stamps the
// mode itself - see QuizLaunchType's docblock.
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
