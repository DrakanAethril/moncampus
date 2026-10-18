<?php

declare(strict_types=1);

namespace App\SeriousGame\Answer;

use App\SeriousGame\Math\MathExpression;

/**
 * An expression in x, and a polynomial asked in a given shape (design/validated/serious-games.md
 * §5.2). Two expressions are the same answer when they agree at five points; the shape
 * (« développer et réduire », « factoriser ») is checked afterwards by the corrector, which signals
 * it without counting the answer wrong.
 */
final class ExpressionChecker implements AnswerChecker
{
    public function supports(AnswerType $type): bool
    {
        return AnswerType::Expression === $type || AnswerType::Polynomial === $type;
    }

    public function value(mixed $raw, AnswerSpec $spec): string
    {
        if (!\is_string($raw) || null === MathExpression::compile($raw)) {
            throw new \InvalidArgumentException('une expression en x lisible était attendue');
        }

        return $raw;
    }

    public function read(string $input, AnswerSpec $spec): ?string
    {
        return null === MathExpression::compile($input) ? null : $input;
    }

    public function same(mixed $a, mixed $b, AnswerSpec $spec, ?float $tolerance = null): bool
    {
        return \is_string($a) && \is_string($b) && MathExpression::equivalent($a, $b);
    }
}
