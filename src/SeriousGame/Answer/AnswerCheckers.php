<?php

declare(strict_types=1);

namespace App\SeriousGame\Answer;

/**
 * The checker of each answer type - one instance each, they hold no state.
 */
final class AnswerCheckers
{
    /** @var array<string, AnswerChecker> */
    private static array $checkers = [];

    public static function for(AnswerType $type): AnswerChecker
    {
        return self::$checkers[$type->value] ??= match ($type) {
            AnswerType::Number, AnswerType::Fraction => new NumberChecker(),
            AnswerType::Power => new PowerChecker(),
            AnswerType::Expression, AnswerType::Polynomial => new ExpressionChecker(),
            AnswerType::Set => new SetChecker(),
            AnswerType::Interval => new IntervalChecker(),
            AnswerType::Scientific => new ScientificChecker(),
            AnswerType::Formula => new FormulaChecker(),
            AnswerType::Text => new TextChecker(),
            AnswerType::Latin => new LatinChecker(),
            AnswerType::Forms => new FormsChecker(),
            AnswerType::Choice => new ChoiceChecker(),
        };
    }
}
