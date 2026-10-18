<?php

declare(strict_types=1);

namespace App\SeriousGame\Answer;

use App\SeriousGame\Math\MathExpression;

/**
 * A single power (design/validated/serious-games.md §5.2): « a^6 », « 2^7 », « 128 », « 10^-3 ».
 *
 * Values are compared through their natural logarithm, the letter `a` read as 1,7 - the reference's
 * `logpow`. That is what makes « 2^7 » and « 128 » the same answer while a power of a letter stays
 * comparable at all.
 */
final class PowerChecker implements AnswerChecker
{
    public function supports(AnswerType $type): bool
    {
        return AnswerType::Power === $type;
    }

    public function value(mixed $raw, AnswerSpec $spec): float
    {
        if (!\is_int($raw) && !\is_float($raw)) {
            throw new \InvalidArgumentException('le logarithme de la puissance était attendu');
        }

        return (float) $raw;
    }

    public function read(string $input, AnswerSpec $spec): ?float
    {
        $s = str_replace('a', '(1.7)', MathExpression::normalize($input));
        $value = MathExpression::evaluate($s);

        return is_finite($value) && $value > 0 ? log($value) : null;
    }

    public function same(mixed $a, mixed $b, AnswerSpec $spec, ?float $tolerance = null): bool
    {
        return \is_float($a) && \is_float($b) && is_finite($a) && is_finite($b) && abs($a - $b) <= ($tolerance ?: 1e-9);
    }
}
