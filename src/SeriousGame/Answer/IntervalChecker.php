<?php

declare(strict_types=1);

namespace App\SeriousGame\Answer;

use App\SeriousGame\Math\MathExpression;

/**
 * An interval (design/validated/serious-games.md §5.2): « ]−∞ ; 4] », « [−2 , 1] » (one comma
 * between brackets), « x ≤ 4 », « −2 ≤ x ≤ 1 », « 3 < x », « S = [3 ; +∞[ ». The brackets count
 * unless the template says `anyClosure` (a variation read on a curve).
 *
 * @phpstan-type Interval array{lo: float, hi: float, lc: bool, hc: bool}
 */
final class IntervalChecker implements AnswerChecker
{
    public function supports(AnswerType $type): bool
    {
        return AnswerType::Interval === $type;
    }

    /** @return Interval */
    public function value(mixed $raw, AnswerSpec $spec): array
    {
        if (!\is_array($raw) || !isset($raw['lo'], $raw['hi']) || !is_numeric($raw['lo']) || !is_numeric($raw['hi'])) {
            throw new \InvalidArgumentException('un intervalle (iv(…), ivLeft(…), ivRight(…)) était attendu');
        }

        return self::make((float) $raw['lo'], (float) $raw['hi'], true === ($raw['lc'] ?? false), true === ($raw['hc'] ?? false));
    }

    /** @return Interval */
    public static function make(float $lo, float $hi, bool $lc, bool $hc): array
    {
        return ['lo' => $lo, 'hi' => $hi, 'lc' => -\INF === $lo ? false : $lc, 'hc' => \INF === $hi ? false : $hc];
    }

    /** @return Interval|null */
    public function read(string $input, AnswerSpec $spec): ?array
    {
        $raw = trim($input);
        if (!str_contains($raw, ';') && 1 === substr_count($raw, ',') && preg_match('/[\[\]]/', $raw)) {
            $raw = str_replace(',', ';', $raw);
        }

        $s = mb_strtolower($raw);
        $s = (string) preg_replace('/[\s\x{00A0}\x{202F}]/u', '', $s);
        $s = (string) preg_replace('/[−–—]/u', '-', $s);
        $s = str_replace(['≤', '=<', '≥', '=>', ','], ['<=', '<=', '>=', '>=', '.'], $s);
        $s = (string) preg_replace('/^s=/', '', $s);
        $s = (string) preg_replace('/^x∈/u', '', $s);

        if (preg_match('/^([\[\]])(.+);(.+)([\[\]])$/u', $s, $m)) {
            $lo = self::bound($m[2]);
            $hi = self::bound($m[3]);
            if (is_nan($lo) || is_nan($hi)) {
                return null;
            }

            return self::make($lo, $hi, '[' === $m[1], ']' === $m[4]);
        }

        if (preg_match('/^(.+?)(<=|<)x(<=|<)(.+)$/u', $s, $d)) {
            $a = NumberChecker::parse($d[1]);
            $b = NumberChecker::parse($d[4]);
            if (!is_finite($a) || !is_finite($b) || $a > $b) {
                return null;
            }

            return self::make($a, $b, '<=' === $d[2], '<=' === $d[3]);
        }

        if (preg_match('/^x(<=|<|>=|>)(.+)$/u', $s, $u)) {
            $op = $u[1];
            $k = NumberChecker::parse($u[2]);
        } elseif (preg_match('/^(.+?)(<=|<|>=|>)x$/u', $s, $w)) {
            $k = NumberChecker::parse($w[1]);
            $op = ['<=' => '>=', '<' => '>', '>=' => '<=', '>' => '<'][$w[2]];
        } else {
            return null;
        }

        if (!is_finite($k)) {
            return null;
        }

        return match ($op) {
            '<' => self::make(-\INF, $k, false, false),
            '<=' => self::make(-\INF, $k, false, true),
            '>' => self::make($k, \INF, false, false),
            default => self::make($k, \INF, true, false),
        };
    }

    public function same(mixed $a, mixed $b, AnswerSpec $spec, ?float $tolerance = null): bool
    {
        if (!\is_array($a) || !\is_array($b) || !isset($a['lo'], $a['hi'], $b['lo'], $b['hi'])) {
            return false;
        }

        $same = static fn (mixed $x, mixed $y): bool => (\is_float($x) || \is_int($x)) && (\is_float($y) || \is_int($y))
            && ($x == $y || (is_finite((float) $x) && is_finite((float) $y) && abs($x - $y) <= 1e-9));

        return $same($a['lo'], $b['lo']) && $same($a['hi'], $b['hi'])
            && ($spec->anyClosure || (($a['lc'] ?? null) === ($b['lc'] ?? null) && ($a['hc'] ?? null) === ($b['hc'] ?? null)));
    }

    private static function bound(string $text): float
    {
        if (preg_match('/^(\+)?(∞|inf|infini|plusinfini)$/u', $text)) {
            return \INF;
        }

        if (preg_match('/^-(∞|inf|infini)$/u', $text) || 'moinsinfini' === $text) {
            return -\INF;
        }

        return NumberChecker::parse(MathExpression::normalize($text));
    }
}
