<?php

declare(strict_types=1);

namespace App\SeriousGame\Answer;

use App\SeriousGame\Math\MathExpression;

/**
 * A number, or a fraction (design/validated/serious-games.md §5.2): « 3,5 », « 3.5 », « 7/2 »,
 * « −3 », « 15 % » or « 15 », « 12 € », « x = 4 », « une baisse de 20 % » for −20. Two numbers are
 * the same answer within the template's tolerance (1e-9 by default).
 */
final class NumberChecker implements AnswerChecker
{
    public function supports(AnswerType $type): bool
    {
        return AnswerType::Number === $type || AnswerType::Fraction === $type;
    }

    public function value(mixed $raw, AnswerSpec $spec): float
    {
        if (!\is_int($raw) && !\is_float($raw)) {
            throw new \InvalidArgumentException('un nombre était attendu');
        }

        return (float) $raw;
    }

    public function read(string $input, AnswerSpec $spec): ?float
    {
        $value = self::parse($input);

        return is_finite($value) ? $value : null;
    }

    public function same(mixed $a, mixed $b, AnswerSpec $spec, ?float $tolerance = null): bool
    {
        return \is_float($a) && \is_float($b) && is_finite($a) && is_finite($b) && abs($a - $b) <= ($tolerance ?: 1e-9);
    }

    /** The reference's parseNum(): NAN when the text is not a number. */
    public static function parse(string $input): float
    {
        $s = MathExpression::normalize($input);
        $s = (string) preg_replace('/^(s=|x=|p=|m=)/', '', $s);
        $s = str_replace(['%', '€'], '', $s);
        $s = (string) preg_replace('/^(une)?baisse(de)?/', '-', $s);
        $s = (string) preg_replace('/^(une)?hausse(de)?/', '', $s);
        $s = (string) preg_replace('/^\+/', '', $s);

        return MathExpression::evaluate($s);
    }
}
