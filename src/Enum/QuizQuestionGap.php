<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Why a question is *incomplete* - created, kept, listed, but not yet playable.
 *
 * Two causes, one mechanism: the screens differ only in the button they offer (attach a file, or
 * open the visual editor). Incomplete is deliberately not an error - an error cannot be imported, an
 * incomplete question must be, otherwise generating questions by AI loses its point as soon as one
 * of them carries an image. The lock sits at launch, where a missing media would really break a
 * passation. See design/comparaison/conception_import_quiz_ia.md, section 5 bis.
 */
enum QuizQuestionGap: string
{
    /** A media was named (or is required by the type) and no file is attached yet. */
    case Media = 'media';

    /** A zones/légende question drawn on an image, whose zones nobody has placed yet. */
    case Zones = 'zones';

    public function labelKey(): string
    {
        return match ($this) {
            self::Media => 'quizQuestionGapMediaLabel',
            self::Zones => 'quizQuestionGapZonesLabel',
        };
    }

    public function actionKey(): string
    {
        return match ($this) {
            self::Media => 'quizQuestionGapMediaActionLabel',
            self::Zones => 'quizQuestionGapZonesActionLabel',
        };
    }
}
