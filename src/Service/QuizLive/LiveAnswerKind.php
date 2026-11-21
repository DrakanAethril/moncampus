<?php

declare(strict_types=1);

namespace App\Service\QuizLive;

use App\Enum\QuestionType;

/**
 * How a question is answered on a phone in a live concours - which is what the player screen draws
 * and what a submission is checked against (App\Service\QuizLive\LiveResponse):
 * - choice: one shape tapped, sent at once (QCM, vrai/faux, image, vote);
 * - multi: several shapes ticked, then « Valider » (QCM multiple);
 * - order: every shape tapped in the right order, then « Valider » (remise en ordre - Kahoot's
 *   Puzzle; the labels are on the board, the phone has the shapes, as everywhere in the room);
 * - number: a slider when the question has a range, else a field (numérique);
 * - text: a word typed (réponse courte);
 * - pairs: each left item given its right-hand one, then « Valider » (apparier, six pairs at most -
 *   App\Service\QuizLive\LiveMatching, which also decides when it is played as a sort instead).
 */
enum LiveAnswerKind: string
{
    case Choice = 'choice';
    case Multi = 'multi';
    case Order = 'order';
    case Number = 'number';
    case Text = 'text';
    case Pairs = 'pairs';

    /** Null for a type the room does not play (QuestionType::isAvailableInLiveContest()). */
    public static function of(QuestionType $type): ?self
    {
        return match ($type) {
            QuestionType::Qcm, QuestionType::VraiFaux, QuestionType::Image, QuestionType::Vote => self::Choice,
            QuestionType::QcmMulti => self::Multi,
            QuestionType::Ordre => self::Order,
            QuestionType::Numerique => self::Number,
            QuestionType::ReponseCourte => self::Text,
            QuestionType::Apparier => self::Pairs,
            QuestionType::TexteATrous, QuestionType::Zone, QuestionType::Legende, QuestionType::Calculee => null,
        };
    }

    /** Whether the phone shows the question's propositions as shapes. */
    public function usesShapes(): bool
    {
        return \in_array($this, [self::Choice, self::Multi, self::Order], true);
    }
}
