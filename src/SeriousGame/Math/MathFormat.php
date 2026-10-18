<?php

declare(strict_types=1);

namespace App\SeriousGame\Math;

/**
 * The French mathematical writing of the question templates (design/validated/serious-games.md
 * §5.2): decimal comma, real minus sign, narrow no-break space between thousands, fractions drawn
 * as stacked spans, exponents as `<sup>`.
 *
 * A faithful port of the helpers of `design/validated/serious-games/gen-maths.reference.js`
 * (engine 2.0.0): the templates were validated on what these functions print, so they print the
 * same. Every function here is pure; the HTML they produce is the restricted set the sanitizer of
 * the serious games lets through (`span.fr`, `sup`, `sub`, `i`, `table.sgm-t`).
 */
final class MathFormat
{
    public const string NBSP = "\u{00A0}";
    public const string THIN = "\u{202F}";
    public const string MINUS = '−';

    /** JavaScript's round(x, n) of the reference: n decimals, 9 by default. */
    public static function round(float|int $x, int $decimals = 9): float
    {
        if (!is_finite((float) $x)) {
            return (float) $x;
        }

        $p = 10 ** $decimals;

        return self::jsRound($x * $p) / $p;
    }

    /** Math.round: halves go up, towards +∞ (PHP's round() takes them away from zero). */
    public static function jsRound(float|int $x): float
    {
        return floor($x + 0.5);
    }

    public static function isInt(float|int $x): bool
    {
        return is_finite((float) $x) && abs($x - self::jsRound($x)) < 1e-9;
    }

    public static function decimals(float|int $x): int
    {
        $x = self::round(abs($x));
        $s = self::plainNumber($x);
        $dot = strpos($s, '.');

        return false === $dot ? 0 : \strlen($s) - $dot - 1;
    }

    public static function gcd(int|float $a, int|float $b): int
    {
        $a = (int) abs(self::jsRound($a));
        $b = (int) abs(self::jsRound($b));
        while (0 !== $b) {
            [$a, $b] = [$b, $a % $b];
        }

        return 0 === $a ? 1 : $a;
    }

    public static function lcm(int|float $a, int|float $b): int
    {
        return (int) (abs((int) $a * (int) $b) / self::gcd($a, $b));
    }

    /** The number with a decimal point and no exponent, at most 12 decimals: what parsing reads back. */
    public static function plainNumber(float|int $x): string
    {
        if (\is_int($x)) {
            return (string) $x;
        }

        if (self::isInt($x) && abs($x) < 1e15) {
            return (string) (int) self::jsRound($x);
        }

        $s = rtrim(rtrim(\sprintf('%.12F', $x), '0'), '.');

        return '-0' === $s ? '0' : $s;
    }

    /**
     * « 1 234,5 », « −3 ». Something that is not a finite number is written « NaN » - which the
     * bench refuses in any statement, so a student never reads it.
     */
    public static function fmt(mixed $x): string
    {
        if (!\is_int($x) && !\is_float($x)) {
            return \is_string($x) ? $x : 'NaN';
        }

        if (!is_finite((float) $x)) {
            return 'NaN';
        }

        $x = self::round($x);
        $negative = $x < 0;
        $s = self::plainNumber(abs($x));
        [$int, $dec] = array_pad(explode('.', $s, 2), 2, null);
        if (\strlen($int) > 4) {
            $int = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', self::THIN, $int);
        }

        return ($negative && 0.0 !== $x ? self::MINUS : '').$int.(null !== $dec && '' !== $dec ? ','.$dec : '');
    }

    public static function pct(float|int $x): string
    {
        return self::fmt($x).self::NBSP.'%';
    }

    /** A percentage, exact when it has at most one decimal, « ≈ » rounded to 0,1 otherwise. */
    public static function approxPct(float|int $x): string
    {
        return self::isInt($x * 10) ? self::pct($x) : '≈'.self::NBSP.self::pct(self::round($x, 1));
    }

    /** A value shown exactly when it has at most two decimals, « ≈ » rounded to 0,1 otherwise. */
    public static function approx(float|int $x): string
    {
        return self::decimals($x) <= 2 ? self::fmt($x) : '≈'.self::NBSP.self::fmt(self::round($x, 1));
    }

    /** A reduced fraction n/d, written as an integer when it is one. */
    public static function frac(int|float $n, int|float $d): string
    {
        [$n, $d] = self::reduce($n, $d);
        if (1 === $d) {
            return self::fmt($n);
        }

        return ($n < 0 ? self::MINUS : '').self::stacked((string) abs($n), (string) $d);
    }

    /** The same reduced fraction, typed: « −3/4 », « 2 ». */
    public static function fracText(int|float $n, int|float $d): string
    {
        [$n, $d] = self::reduce($n, $d);

        return 1 === $d ? self::fmt($n) : ($n < 0 ? self::MINUS : '').abs($n).'/'.$d;
    }

    /** A fraction drawn as given, never reduced: what a step of a method shows. */
    public static function stacked(string|int|float $numerator, string|int|float $denominator): string
    {
        return '<span class="fr"><span class="n">'.self::piece($numerator).'</span><span class="d">'.self::piece($denominator).'</span></span>';
    }

    /** A value that may be a fraction: a short decimal when it is one, the fraction otherwise. */
    public static function num(int|float $n, int|float $d): string
    {
        $v = $n / $d;
        $short = self::decimals($v) <= 2 && !(1 != $d && abs($v) < 1 && self::decimals($v) > 1);

        return $short ? self::fmt($v) : self::frac($n, $d);
    }

    /** b<sup>e</sup>, with a real minus sign in a negative exponent. */
    public static function sup(string|int|float $base, string|int|float $exponent): string
    {
        $e = \is_string($exponent) ? $exponent : ($exponent < 0 ? self::MINUS.self::fmt(abs($exponent)) : self::fmt($exponent));

        return self::piece($base).'<sup>'.$e.'</sup>';
    }

    /** A letter in italics, as a variable is written. */
    public static function variable(string $name): string
    {
        return '<i>'.$name.'</i>';
    }

    /**
     * A polynomial from its coefficients, highest degree first: « 3<i>x</i>² − <i>x</i> + 2 ».
     *
     * @param list<int|float> $coefficients
     */
    public static function poly(array $coefficients, bool $plain = false): string
    {
        $degree = \count($coefficients) - 1;
        $out = '';
        foreach ($coefficients as $i => $a) {
            $power = $degree - $i;
            if (0.0 === self::round($a)) {
                continue;
            }

            $abs = abs($a);
            $x = $plain ? 'x' : '<i>x</i>';
            $core = 0 === $power ? self::fmt($abs) : (1 == $abs ? '' : self::fmt($abs)).$x.(2 === $power ? '²' : (3 === $power ? '³' : ''));
            $out .= '' === $out ? ($a < 0 ? self::MINUS : '').$core : ($a < 0 ? ' − ' : ' + ').$core;
        }

        return '' === $out ? '0' : $out;
    }

    /**
     * The same polynomial as an expression the parser reads: « (3)*x^2+(-1)*x^1+(2)*x^0 ».
     *
     * @param list<int|float> $coefficients
     */
    public static function polyValue(array $coefficients): string
    {
        $degree = \count($coefficients) - 1;
        $terms = [];
        foreach ($coefficients as $i => $a) {
            $terms[] = '('.self::plainNumber($a).')*x^'.($degree - $i);
        }

        return implode('+', $terms);
    }

    /**
     * « S = { −3 ; 2 } », sorted, or « S = ∅ ».
     *
     * @param list<int|float>    $values
     * @param list<string>|null $shown  the writing of each value, in the order of $values
     */
    public static function setHtml(array $values, ?array $shown = null): string
    {
        if ([] === $values) {
            return 'S = ∅';
        }

        $pairs = [];
        foreach ($values as $i => $value) {
            $pairs[] = [$value, $shown[$i] ?? self::fmt($value)];
        }
        usort($pairs, static fn (array $p, array $q): int => $p[0] <=> $q[0]);

        return 'S = { '.implode(' ; ', array_column($pairs, 1)).' }';
    }

    /** @param list<int|float> $values */
    public static function setText(array $values): string
    {
        return [] === $values ? '∅' : implode(' ; ', array_map(self::fmt(...), $values));
    }

    /**
     * « ]−∞ ; 4] ».
     *
     * @param array{lo: float|int, hi: float|int, lc: bool, hc: bool} $interval
     */
    public static function intervalHtml(array $interval, ?string $loShown = null, ?string $hiShown = null): string
    {
        $bound = static fn (float|int $x, ?string $shown): string => match (true) {
            -\INF === (float) $x => '−∞',
            \INF === (float) $x => '+∞',
            default => $shown ?? self::fmt($x),
        };

        return ($interval['lc'] ? '[' : ']').$bound($interval['lo'], $loShown).' ; '.$bound($interval['hi'], $hiShown).($interval['hc'] ? ']' : '[');
    }

    /** « 3,2 × 10<sup>−4</sup> ». */
    public static function sci(float|int $mantissa, int $exponent): string
    {
        return self::fmt($mantissa).' × '.self::sup('10', $exponent);
    }

    /** The scientific notation typed: « 3,2 × 10^-4 ». */
    public static function sciText(float|int $mantissa, int $exponent): string
    {
        return self::fmt($mantissa).' × 10^'.$exponent;
    }

    /**
     * A small table, first cell of each row a header: `table([['Note', 12, 15], ['Coef', 1, 2]])`.
     *
     * @param list<list<mixed>> $rows
     * @param bool              $headerRow whether the first row is a header row too
     */
    public static function table(array $rows, bool $headerRow = false): string
    {
        $html = '<table class="sgm-t">';
        foreach ($rows as $r => $row) {
            $html .= '<tr>';
            foreach ($row as $c => $cell) {
                $tag = 0 === $c || ($headerRow && 0 === $r) ? 'th' : 'td';
                $html .= '<'.$tag.'>'.self::piece($cell).'</'.$tag.'>';
            }
            $html .= '</tr>';
        }

        return $html.'</table>';
    }

    /**
     * The HTML of a statement read as typed text: fractions as « a/b » - bracketed when a part is
     * compound, so that « (v − v₀)/a » reads back as it is meant -, exponents as « ^n », tags gone.
     */
    public static function plain(string $html): string
    {
        $s = (string) preg_replace_callback(
            '#<span class="fr"><span class="n">([^<]*)</span><span class="d">([^<]*)</span></span>#',
            static fn (array $m): string => self::bracket(strip_tags($m[1])).'/'.self::bracket(strip_tags($m[2])),
            // Italics inside a fraction are dropped first, so the pattern above sees plain parts.
            (string) preg_replace('#</?i>#', '', $html),
        );
        $s = (string) preg_replace('#<sup>([^<]*)</sup>#', '^$1', $s);
        $s = strip_tags($s);

        return html_entity_decode(str_replace('&nbsp;', ' ', $s), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }

    /** French elision: « de » before a vowel becomes « d’ ». */
    public static function de(string $word): string
    {
        return (preg_match('/^[aeiouyhéèêàâîôû]/iu', $word) ? 'd’' : 'de ').$word;
    }

    /** French elision: « la » before a vowel becomes « l’ ». */
    public static function la(string $word): string
    {
        return (preg_match('/^[aeiouyhéèêàâîôû]/iu', $word) ? 'l’' : 'la ').$word;
    }

    /** @return array{0: int, 1: int} */
    private static function reduce(int|float $n, int|float $d): array
    {
        $n = (int) self::jsRound($n);
        $d = (int) self::jsRound($d);
        if ($d < 0) {
            $n = -$n;
            $d = -$d;
        }
        $g = self::gcd($n, $d);

        return [intdiv($n, $g), 0 === $d ? 0 : intdiv($d, $g)];
    }

    private static function piece(mixed $value): string
    {
        return \is_int($value) || \is_float($value) ? self::fmt($value) : (\is_scalar($value) ? (string) $value : '');
    }

    private static function bracket(string $part): string
    {
        $trimmed = trim($part);

        return preg_match('/[\s+\-−×*÷]/u', ltrim($trimmed, '−-')) ? '('.$trimmed.')' : $trimmed;
    }
}
