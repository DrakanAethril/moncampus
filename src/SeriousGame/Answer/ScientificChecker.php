<?php

declare(strict_types=1);

namespace App\SeriousGame\Answer;

use App\SeriousGame\Math\MathExpression;

/**
 * A scientific notation (design/validated/serious-games.md §5.2): « 3,2 × 10^-4 », « 3.2e-4 »,
 * « 3,2*10^(-4) », « 10^5 ». Two writings are the same answer when mantissa and exponent agree;
 * an equal value with a mantissa outside [1 ; 10[ is recognised by the corrector as its own error.
 *
 * @phpstan-type Scientific array{m: float, e: int}
 */
final class ScientificChecker implements AnswerChecker
{
    public function supports(AnswerType $type): bool
    {
        return AnswerType::Scientific === $type;
    }

    /** @return Scientific */
    public function value(mixed $raw, AnswerSpec $spec): array
    {
        if (!\is_array($raw) || !isset($raw['m'], $raw['e']) || !is_numeric($raw['m']) || !is_numeric($raw['e'])) {
            throw new \InvalidArgumentException('une notation scientifique (sciNumber(m, e)) était attendue');
        }

        return ['m' => round((float) $raw['m'], 9), 'e' => (int) $raw['e']];
    }

    /** @return Scientific|null */
    public function read(string $input, AnswerSpec $spec): ?array
    {
        $s = (string) preg_replace('/^\+/', '', MathExpression::normalize($input));
        if (preg_match('/^(-?[0-9]*\.?[0-9]+)(?:\*|x)?10\^\(?(-?\d+)\)?$/', $s, $m) || preg_match('/^(-?[0-9]*\.?[0-9]+)e(-?\d+)$/', $s, $m)) {
            return ['m' => (float) $m[1], 'e' => (int) $m[2]];
        }

        if (preg_match('/^10\^\(?(-?\d+)\)?$/', $s, $p)) {
            return ['m' => 1.0, 'e' => (int) $p[1]];
        }

        return null;
    }

    public function same(mixed $a, mixed $b, AnswerSpec $spec, ?float $tolerance = null): bool
    {
        return \is_array($a) && \is_array($b) && isset($a['m'], $a['e'], $b['m'], $b['e'])
            && \is_numeric($a['m']) && \is_numeric($b['m'])
            && abs((float) $a['m'] - (float) $b['m']) <= 1e-9 && $a['e'] === $b['e'];
    }

    /** @param Scientific $value */
    public static function numeric(array $value): float
    {
        return $value['m'] * 10 ** $value['e'];
    }

    /** @param Scientific $value */
    public static function isNormalised(array $value): bool
    {
        return abs($value['m']) >= 1 && abs($value['m']) < 10;
    }
}
