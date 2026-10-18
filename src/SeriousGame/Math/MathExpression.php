<?php

declare(strict_types=1);

namespace App\SeriousGame\Math;

/**
 * Reads what a student typed as a mathematical expression in one unknown, x: numbers with a comma
 * or a point, + − × ÷ / ^ ² ³ √, brackets and implicit products (« 3x », « 2(x+1) »).
 *
 * A port of the parser of `gen-maths.reference.js`: compile() returns a function of x - or null
 * when the text does not read - and an expression without x is a number (call it with null). Two
 * expressions are equal when they agree at five points (equivalent()), which is how « 2(x + 1) »
 * and « 2x + 2 » are the same answer.
 */
final class MathExpression
{
    /** The five points two expressions in x are compared at. */
    private const array POINTS = [-2.3, -0.7, 0.45, 1.9, 3.1];

    private string $source;
    private int $at = 0;

    private function __construct(string $source)
    {
        $this->source = $source;
    }

    /** The writing every reading starts from: lower case, no spaces, ASCII operators, a decimal point. */
    public static function normalize(string $text): string
    {
        $s = mb_strtolower($text);
        $s = (string) preg_replace('/[\s\x{00A0}\x{202F}≈]/u', '', $s);
        $s = (string) preg_replace('/[−–—]/u', '-', $s);
        $s = (string) preg_replace('/[×·⋅∙]/u', '*', $s);

        return str_replace(['÷', ',', '²', '³', 'sqrt', '**'], ['/', '.', '^2', '^3', '√', '^'], $s);
    }

    /**
     * @return (\Closure(float|null): float)|null
     */
    public static function compile(string $text): ?\Closure
    {
        $s = self::normalize($text);
        if ('' === $s) {
            return null;
        }

        $parser = new self($s);
        try {
            $node = $parser->expr();
        } catch (\RuntimeException) {
            return null;
        }

        return $parser->at === mb_strlen($s) ? $node : null;
    }

    /** The value of an expression without x, NAN when it does not read or needs x. */
    public static function evaluate(string $text): float
    {
        $f = self::compile($text);
        if (null === $f) {
            return \NAN;
        }

        try {
            return $f(null);
        } catch (\RuntimeException) {
            return \NAN;
        }
    }

    /** Equal at five points, both finite everywhere - the rule for expressions in x. */
    public static function equivalent(string|\Closure $a, string|\Closure $b): bool
    {
        $fa = \is_string($a) ? self::compile($a) : $a;
        $fb = \is_string($b) ? self::compile($b) : $b;
        if (null === $fa || null === $fb) {
            return false;
        }

        try {
            foreach (self::POINTS as $x) {
                $u = $fa($x);
                $w = $fb($x);
                if (!is_finite($u) || !is_finite($w) || abs($u - $w) > 1e-7 * max(1, abs($u))) {
                    return false;
                }
            }
        } catch (\RuntimeException|\DivisionByZeroError|\ArithmeticError) {
            return false;
        }

        return true;
    }

    /** Written without brackets: what « développer » asks. */
    public static function isExpanded(string $input): bool
    {
        return !str_contains(self::normalize($input), '(');
    }

    /** The number of top-level terms once the leading sign is dropped. */
    public static function reducedTerms(string $input): int
    {
        return self::topLevelTerms((string) preg_replace('/^[-+]/', '', self::normalize($input)));
    }

    /** A product of factors at the top level, at least one of them bracketed: what « factoriser » asks. */
    public static function isProduct(string $input): bool
    {
        $s = (string) preg_replace('/^[-+]/', '', self::normalize($input));

        return str_contains($s, '(') && 1 === self::topLevelTerms($s);
    }

    /**
     * Inside every bracket of a factored answer, degree at most 1 and - for « au maximum » -
     * coefficients with no common factor.
     */
    public static function bracketsLinearCoprime(string $input): bool
    {
        $s = self::normalize($input);
        $out = true;
        $depth = 0;
        $start = -1;
        $chars = mb_str_split($s);
        foreach ($chars as $i => $c) {
            if ('(' === $c) {
                if (0 === $depth) {
                    $start = $i + 1;
                }
                ++$depth;
            } elseif (')' === $c) {
                --$depth;
                if (0 === $depth) {
                    $f = self::compile(implode('', \array_slice($chars, $start, $i - $start)));
                    if (null === $f) {
                        return false;
                    }

                    try {
                        [$f0, $f1, $f2, $f3] = [$f(0.0), $f(1.0), $f(2.0), $f(3.0)];
                    } catch (\RuntimeException) {
                        return false;
                    }

                    if (abs($f2 - 2 * $f1 + $f0) > 1e-9 || abs($f3 - 2 * $f2 + $f1) > 1e-9) {
                        $out = false;

                        continue;
                    }

                    $a = $f1 - $f0;
                    $b = $f0;
                    if (MathFormat::isInt($a) && MathFormat::isInt($b) && abs($a) > 0 && MathFormat::gcd($a, $b) > 1) {
                        $out = false;
                    }
                }
            }
        }

        return $out;
    }

    /** How many of the coefficients of degree 0, 1 and 2 are not zero. */
    public static function coefficientCount(string $expression): int
    {
        $f = self::compile($expression);
        if (null === $f) {
            return 1;
        }

        try {
            $c0 = $f(0.0);
            $f1 = $f(1.0);
            $f2 = $f(2.0);
        } catch (\RuntimeException) {
            return 1;
        }

        $c2 = ($f2 - 2 * $f1 + $c0) / 2;
        $c1 = $f1 - $c0 - $c2;

        return \count(array_filter([$c0, $c1, $c2], static fn (float $c): bool => abs($c) > 1e-9));
    }

    private static function topLevelTerms(string $s): int
    {
        $depth = 0;
        $terms = 1;
        $chars = mb_str_split($s);
        foreach ($chars as $i => $c) {
            if ('(' === $c) {
                ++$depth;
            } elseif (')' === $c) {
                --$depth;
            } elseif (0 === $depth && ('+' === $c || '-' === $c) && $i > 0 && !preg_match('#[*/^(]#', $chars[$i - 1])) {
                ++$terms;
            }
        }

        return $terms;
    }

    private function peek(): ?string
    {
        $c = mb_substr($this->source, $this->at, 1);

        return '' === $c ? null : $c;
    }

    private function next(): string
    {
        $c = mb_substr($this->source, $this->at, 1);
        ++$this->at;

        return $c;
    }

    private function atomStart(?string $c): bool
    {
        return null !== $c && 1 === preg_match('/[0-9.x(√]/u', $c);
    }

    /** @return \Closure(float|null): float */
    private function expr(): \Closure
    {
        $node = $this->term();
        while ('+' === $this->peek() || '-' === $this->peek()) {
            $op = $this->next();
            $rhs = $this->term();
            $lhs = $node;
            $node = '+' === $op
                ? static fn (?float $x): float => $lhs($x) + $rhs($x)
                : static fn (?float $x): float => $lhs($x) - $rhs($x);
        }

        return $node;
    }

    /** @return \Closure(float|null): float */
    private function term(): \Closure
    {
        $node = $this->unary();
        while (true) {
            $c = $this->peek();
            $lhs = $node;
            if ('*' === $c) {
                $this->next();
                $rhs = $this->unary();
                $node = static fn (?float $x): float => $lhs($x) * $rhs($x);
            } elseif ('/' === $c) {
                $this->next();
                $rhs = $this->unary();
                $node = static fn (?float $x): float => self::divide($lhs($x), $rhs($x));
            } elseif ($this->atomStart($c)) {
                $rhs = $this->power();
                $node = static fn (?float $x): float => $lhs($x) * $rhs($x);
            } else {
                break;
            }
        }

        return $node;
    }

    /** @return \Closure(float|null): float */
    private function unary(): \Closure
    {
        if ('-' === $this->peek()) {
            $this->next();
            $operand = $this->unary();

            return static fn (?float $x): float => -$operand($x);
        }

        if ('+' === $this->peek()) {
            $this->next();

            return $this->unary();
        }

        return $this->power();
    }

    /** @return \Closure(float|null): float */
    private function power(): \Closure
    {
        $base = $this->atom();
        if ('^' === $this->peek()) {
            $this->next();
            $exponent = $this->unary();

            return static fn (?float $x): float => self::pow($base($x), $exponent($x));
        }

        return $base;
    }

    /** @return \Closure(float|null): float */
    private function atom(): \Closure
    {
        $c = $this->peek();
        if ('(' === $c) {
            $this->next();
            $inner = $this->expr();
            if (')' !== $this->peek()) {
                throw new \RuntimeException('paren');
            }
            $this->next();

            return $inner;
        }

        if ('√' === $c) {
            $this->next();
            $operand = $this->power();

            return static fn (?float $x): float => sqrt($operand($x));
        }

        if ('x' === $c) {
            $this->next();

            return static function (?float $x): float {
                if (null === $x) {
                    throw new \RuntimeException('var');
                }

                return $x;
            };
        }

        if (!preg_match('/^[0-9]*\.?[0-9]+/', mb_substr($this->source, $this->at), $m)) {
            throw new \RuntimeException('token');
        }

        $this->at += \strlen($m[0]);
        $value = (float) $m[0];

        return static fn (?float $x): float => $value;
    }

    private static function divide(float $a, float $b): float
    {
        if (0.0 === $b) {
            return 0.0 === $a || is_nan($a) ? \NAN : ($a > 0 ? \INF : -\INF);
        }

        return $a / $b;
    }

    private static function pow(float $a, float $b): float
    {
        if (0.0 === $a && $b < 0) {
            return \INF;
        }

        return $a ** $b;
    }
}
