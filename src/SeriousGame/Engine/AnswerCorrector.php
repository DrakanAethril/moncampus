<?php

declare(strict_types=1);

namespace App\SeriousGame\Engine;

use App\SeriousGame\Answer\AnswerCheckers;
use App\SeriousGame\Answer\AnswerType;
use App\SeriousGame\Answer\ScientificChecker;
use App\SeriousGame\Math\MathExpression;
use App\SeriousGame\Template\TemplateInterpreter;

/**
 * Corrects what a student typed against a generated question (design/validated/serious-games.md
 * §5.2, §5.5) - the reference's `check()`, to the letter.
 *
 * - « Je ne sais pas » is not right and names no error.
 * - A text that does not read is asked again (`unreadable`), as is a right value in the wrong shape
 *   (`formIssue`: « développer et réduire » given in brackets) - neither counts wrong.
 * - A wrong answer is matched against the question's typical wrong answers: for a number, the
 *   closest one within its tolerance (« ≈ » values within 0,051); otherwise the first equal one.
 * - Two errors read from the shape rather than a value: a factorisation that is not « au maximum »,
 *   and a scientific notation whose mantissa is outside [1 ; 10[ - both the template's
 *   `shapeMisconception`.
 */
final class AnswerCorrector
{
    public const string DONT_KNOW = 'dont_know';

    public const string FORM_DEVELOPED = 'Écrivez la forme développée, sans parenthèses.';
    public const string FORM_REDUCED = 'Réduisez : regroupez les termes de même degré.';
    public const string FORM_FACTORED = 'Écrivez une forme factorisée : un produit de facteurs.';

    public function check(GeneratedQuestion $question, string $input): CheckResult
    {
        if (self::DONT_KNOW === $input) {
            return new CheckResult(false, dontKnow: true);
        }

        $spec = $question->spec;
        $checker = AnswerCheckers::for($spec->type);
        $isExpression = AnswerType::Expression === $spec->type || AnswerType::Polynomial === $spec->type;

        if ($isExpression && $input === $question->answerValue) {
            return new CheckResult(true);
        }

        $got = $checker->read($input, $spec);
        if (null === $got) {
            return new CheckResult(false, unreadable: true);
        }

        if ($checker->same($got, $question->answerValue, $spec, $spec->tolerance)) {
            if (AnswerType::Scientific === $spec->type && \is_array($got) && !ScientificChecker::isNormalised($got)) {
                return new CheckResult(false, $question->shapeMisconception);
            }

            if ('developed' === $spec->form) {
                if (!MathExpression::isExpanded($input)) {
                    return new CheckResult(false, formIssue: self::FORM_DEVELOPED);
                }
                if (\is_string($question->answerValue) && MathExpression::reducedTerms($input) > TemplateInterpreter::expectedTerms($question->answerValue)) {
                    return new CheckResult(false, formIssue: self::FORM_REDUCED);
                }
            }

            if ('factored' === $spec->form || 'factoredMax' === $spec->form) {
                if (!MathExpression::isProduct($input)) {
                    return new CheckResult(false, formIssue: self::FORM_FACTORED);
                }
                if ('factoredMax' === $spec->form && !MathExpression::bracketsLinearCoprime($input)) {
                    return new CheckResult(false, $question->shapeMisconception);
                }
            }

            return new CheckResult(true);
        }

        // A scientific notation equal in value but badly normalised.
        if (AnswerType::Scientific === $spec->type && \is_array($got) && \is_array($question->answerValue)) {
            /** @var array{m: float, e: int} $got */
            /** @var array{m: float, e: int} $expected */
            $expected = $question->answerValue;
            $value = ScientificChecker::numeric($expected);
            if (abs(ScientificChecker::numeric($got) - $value) <= 1e-9 * abs($value)) {
                return new CheckResult(false, $question->shapeMisconception);
            }
        }

        $hit = null;
        if (TemplateInterpreter::isNumeric($spec->type) && \is_float($got)) {
            $best = \INF;
            foreach ($question->wrongs as $wrong) {
                if (!\is_float($wrong['value'])) {
                    continue;
                }
                $distance = abs($got - $wrong['value']);
                $tolerance = str_contains($wrong['display'], '≈') ? .051 : max($spec->tolerance ?: 1e-9, 1e-9 * abs($wrong['value']));
                if ($distance <= $tolerance && $distance < $best) {
                    $best = $distance;
                    $hit = $wrong['misconception'];
                }
            }
        } else {
            foreach ($question->wrongs as $wrong) {
                if ($checker->same($got, $wrong['value'], $spec, $spec->tolerance)) {
                    $hit = $wrong['misconception'];
                    break;
                }
            }
        }

        return new CheckResult(false, $hit);
    }
}
