<?php

declare(strict_types=1);

namespace App\SeriousGame\Answer;

use App\SeriousGame\Math\MathExpression;

/**
 * A set of numbers (design/validated/serious-games.md §5.2): « −3 ; 2 », « S = { 2 ; −3 } »,
 * « x = 2 ou x = −3 », « ∅ », « aucune solution ». The order does not matter; the count does.
 */
final class SetChecker implements AnswerChecker
{
    public function supports(AnswerType $type): bool
    {
        return AnswerType::Set === $type;
    }

    /** @return list<float> */
    public function value(mixed $raw, AnswerSpec $spec): array
    {
        if (!\is_array($raw)) {
            throw new \InvalidArgumentException('une liste de nombres était attendue');
        }

        $values = [];
        foreach ($raw as $item) {
            if (!\is_int($item) && !\is_float($item)) {
                throw new \InvalidArgumentException('une liste de nombres était attendue');
            }
            $values[] = (float) $item;
        }

        return $values;
    }

    /** @return list<float>|null */
    public function read(string $input, AnswerSpec $spec): ?array
    {
        $s = MathExpression::normalize($input);
        if (preg_match('/^(s=)?(∅|\{\}|aucune?(solution)?|pasdesolution|ensemblevide|vide)$/u', $s)) {
            return [];
        }

        $s = (string) preg_replace('/^s=/', '', $s);
        $s = str_replace(['{', '}', 'x='], '', $s);
        $parts = array_values(array_filter(preg_split('/;|ou|et/', $s) ?: [], static fn (string $p): bool => '' !== $p));
        $values = array_map(NumberChecker::parse(...), $parts);

        foreach ($values as $value) {
            if (!is_finite($value)) {
                return null;
            }
        }

        return $values;
    }

    public function same(mixed $a, mixed $b, AnswerSpec $spec, ?float $tolerance = null): bool
    {
        if (!\is_array($a) || !\is_array($b) || \count($a) !== \count($b)) {
            return false;
        }

        $x = array_values($a);
        $y = array_values($b);
        sort($x);
        sort($y);
        foreach ($x as $k => $u) {
            if (!\is_float($u) && !\is_int($u) || (!\is_float($y[$k]) && !\is_int($y[$k])) || abs($u - $y[$k]) > ($tolerance ?: 1e-9)) {
                return false;
            }
        }

        return true;
    }
}
