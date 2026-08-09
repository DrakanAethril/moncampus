<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How a student fills the blanks of a QuestionType::TexteATrous question - screens 2a/2b of
 * design/design_handoff_quiz. Stored inside QuizQuestion::$blanksConfig's "mode" key rather than in
 * a column of its own (see App\Entity\QuizQuestionDefinitionTrait).
 */
enum BlankMode: string
{
    // Screen 2a: one correct word per blank, shuffled into a bank alongside optional distractors;
    // the student clicks a word then a blank to place it.
    case Banque = 'banque';

    // Screen 2b: the student types into each blank; several accepted variants per blank, one is
    // enough. Case/accent folding and 1-character tolerance are opt-in per question.
    case Libre = 'libre';

    public function labelKey(): string
    {
        return match ($this) {
            self::Banque => 'blankModeBanqueLabel',
            self::Libre => 'blankModeLibreLabel',
        };
    }

    public function descriptionKey(): string
    {
        return match ($this) {
            self::Banque => 'blankModeBanqueDescription',
            self::Libre => 'blankModeLibreDescription',
        };
    }
}
