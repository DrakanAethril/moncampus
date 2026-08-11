<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Evaluates the arithmetic expression a teacher wrote as the answer of a "calculée" question
 * ("v * t", "sqrt(2 * g * h)", "round(m / (t * t), 2)") against the values drawn for one student.
 *
 * Hand-written rather than delegated to eval() or to Symfony's ExpressionLanguage, and that is the
 * whole point: the formula is *teacher input* stored in the database and evaluated on the server at
 * grading time. eval() would make the question editor a remote code execution hole, and
 * ExpressionLanguage - while sandboxed against PHP calls - still exposes property/method access and
 * a far larger surface than "arithmetic over a handful of numbers". What is not in the grammar
 * below cannot be expressed at all.
 *
 * A recursive-descent parser over a tokenizer, standard precedence:
 *   expression := term (('+' | '-') term)*
 *   term       := power (('*' | '/' | '%') power)*
 *   power      := unary ('^' power)?          right-associative, as in mathematics
 *   unary      := ('-' | '+')? primary
 *   primary    := number | constant | variable | function '(' args ')' | '(' expression ')'
 *
 * Every failure - a syntax error, an unknown name, a division by zero, a square root of a negative
 * number - returns null rather than throwing. A question whose formula cannot be evaluated grades
 * as "nobody can get this right" (App\Service\QuizAnswerChecker), which is the same rule the other
 * types apply to an unfinished definition; a fatal here would take down a whole passation instead.
 */
final class FormulaEvaluator
{
    /** Constants a formula may name, on top of its own variables. */
    private const array CONSTANTS = ['pi' => \M_PI, 'e' => \M_E];

    /**
     * name => [arity, callable]. Arity is the exact count, or -1 for "one or more" (min/max), or
     * -2 for "one or two" (round, log). Deliberately closed: trigonometry, roots, rounding and the
     * usual logarithms cover school physics and mathematics, and anything else is better written
     * out than hidden in a function nobody can look up.
     *
     * @var array<string, array{int, callable}>
     */
    private const array FUNCTIONS = [
        'abs' => [1, 'abs'],
        'sqrt' => [1, 'sqrt'],
        'exp' => [1, 'exp'],
        'ln' => [1, 'log'],
        'log10' => [1, 'log10'],
        'sin' => [1, 'sin'],
        'cos' => [1, 'cos'],
        'tan' => [1, 'tan'],
        'asin' => [1, 'asin'],
        'acos' => [1, 'acos'],
        'atan' => [1, 'atan'],
        'floor' => [1, 'floor'],
        'ceil' => [1, 'ceil'],
        'round' => [-2, 'round'],
        'log' => [-2, 'log'],
        'pow' => [2, 'pow'],
        'min' => [-1, 'min'],
        'max' => [-1, 'max'],
    ];

    /** @var list<array{type: string, value: string}> */
    private array $tokens = [];

    private int $position = 0;

    /** @var array<string, float> */
    private array $variables = [];

    /**
     * The formula's value for these variables, or null when it cannot be computed at all.
     *
     * @param array<string, float> $variables
     */
    public static function evaluate(string $formula, array $variables = []): ?float
    {
        return (new self())->run($formula, $variables);
    }

    /**
     * The parser's own state lives on a throwaway instance rather than on a shared service: the
     * tokens and the cursor are per-evaluation, and a long-lived FrankenPHP worker holding a
     * half-parsed formula between two gradings is exactly the bug that would never reproduce.
     *
     * @param array<string, float> $variables
     */
    private function run(string $formula, array $variables): ?float
    {
        $this->variables = $variables;
        $this->position = 0;

        try {
            $this->tokens = self::tokenize($formula);
            if ([] === $this->tokens) {
                return null;
            }

            $value = $this->parseExpression();
            if ($this->position < \count($this->tokens)) {
                return null; // trailing junk: "2 + 3)" or "2 3"
            }
        } catch (\Throwable) {
            return null;
        }

        // NAN/INF are not answers - a division by zero or a sqrt of a negative reaches here as one.
        return is_finite($value) ? $value : null;
    }

    /**
     * The variable names a formula reads, in order of first appearance - what the editor lists so a
     * teacher can see at a glance that their formula and their statement agree. Function and
     * constant names are excluded: they are not the teacher's to define.
     *
     * @return list<string>
     */
    public static function variableNames(string $formula): array
    {
        try {
            $tokens = self::tokenize($formula);
        } catch (\Throwable) {
            // Unreadable is not the same as "has no variables", but every caller here is a live
            // editor hint over a formula still being typed - and none of them may throw.
            return [];
        }

        $names = [];
        foreach ($tokens as $token) {
            if ('name' !== $token['type']) {
                continue;
            }
            $lower = strtolower($token['value']);
            if (isset(self::CONSTANTS[$lower]) || isset(self::FUNCTIONS[$lower])) {
                continue;
            }
            if (!\in_array($token['value'], $names, true)) {
                $names[] = $token['value'];
            }
        }

        return $names;
    }

    /** Whether the formula parses at all, ignoring the values - the editor's "formule valide" check. */
    public static function isSyntaxValid(string $formula): bool
    {
        $names = self::variableNames($formula);
        // Any value will do for a syntax check; 1 avoids the division-by-zero that would make a
        // perfectly good "a / b" look broken.
        $probe = array_fill_keys($names, 1.0);

        return null !== self::evaluate($formula, $probe);
    }

    /**
     * @return list<array{type: string, value: string}>
     *
     * @throws \InvalidArgumentException on a character the grammar has no meaning for
     */
    private static function tokenize(string $formula): array
    {
        $tokens = [];
        $length = \strlen($formula);

        for ($i = 0; $i < $length; ++$i) {
            $char = $formula[$i];

            if (' ' === $char || "\t" === $char || "\n" === $char || "\r" === $char) {
                continue;
            }

            if (str_contains('+-*/%^(),', $char)) {
                $tokens[] = ['type' => $char, 'value' => $char];
                continue;
            }

            if (ctype_digit($char) || '.' === $char) {
                $number = '';
                while ($i < $length && (ctype_digit($formula[$i]) || '.' === $formula[$i])) {
                    $number .= $formula[$i++];
                }
                --$i;
                if (!is_numeric($number)) {
                    throw new \InvalidArgumentException('bad number');
                }
                $tokens[] = ['type' => 'number', 'value' => $number];
                continue;
            }

            if (ctype_alpha($char) || '_' === $char) {
                $name = '';
                while ($i < $length && (ctype_alnum($formula[$i]) || '_' === $formula[$i])) {
                    $name .= $formula[$i++];
                }
                --$i;
                $tokens[] = ['type' => 'name', 'value' => $name];
                continue;
            }

            throw new \InvalidArgumentException('unexpected character');
        }

        return $tokens;
    }

    /**
     * @phpstan-impure advances $position - without this, static analysis assumes the cursor never
     *                 moves and reads the "trailing junk" guard in run() as dead code
     */
    private function parseExpression(): float
    {
        $value = $this->parseTerm();

        while (null !== ($type = $this->peekType()) && ('+' === $type || '-' === $type)) {
            ++$this->position;
            $right = $this->parseTerm();
            $value = '+' === $type ? $value + $right : $value - $right;
        }

        return $value;
    }

    /**
     * @phpstan-impure advances $position - without this, static analysis assumes the cursor never
     *                 moves and reads the "trailing junk" guard in run() as dead code
     */
    private function parseTerm(): float
    {
        $value = $this->parsePower();

        while (null !== ($type = $this->peekType()) && ('*' === $type || '/' === $type || '%' === $type)) {
            ++$this->position;
            $right = $this->parsePower();
            if (('/' === $type || '%' === $type) && 0.0 === $right) {
                throw new \InvalidArgumentException('division by zero');
            }
            $value = match ($type) {
                '*' => $value * $right,
                '/' => $value / $right,
                default => fmod($value, $right),
            };
        }

        return $value;
    }

    /**
     * @phpstan-impure advances $position - without this, static analysis assumes the cursor never
     *                 moves and reads the "trailing junk" guard in run() as dead code
     */
    private function parsePower(): float
    {
        $base = $this->parseUnary();

        if ('^' === $this->peekType()) {
            ++$this->position;

            // Right-associative: 2^3^2 is 2^(3^2), the way it is written on paper.
            return $base ** $this->parsePower();
        }

        return $base;
    }

    /**
     * @phpstan-impure advances $position - without this, static analysis assumes the cursor never
     *                 moves and reads the "trailing junk" guard in run() as dead code
     */
    private function parseUnary(): float
    {
        $type = $this->peekType();
        if ('-' === $type || '+' === $type) {
            ++$this->position;

            return '-' === $type ? -$this->parseUnary() : $this->parseUnary();
        }

        return $this->parsePrimary();
    }

    /**
     * @phpstan-impure advances $position - without this, static analysis assumes the cursor never
     *                 moves and reads the "trailing junk" guard in run() as dead code
     */
    private function parsePrimary(): float
    {
        $token = $this->tokens[$this->position] ?? throw new \InvalidArgumentException('unexpected end');
        ++$this->position;

        if ('number' === $token['type']) {
            return (float) $token['value'];
        }

        if ('(' === $token['type']) {
            $value = $this->parseExpression();
            $this->expect(')');

            return $value;
        }

        if ('name' !== $token['type']) {
            throw new \InvalidArgumentException('unexpected token');
        }

        $name = $token['value'];
        $lower = strtolower($name);

        if ('(' === $this->peekType()) {
            ++$this->position;
            $arguments = [];
            if (')' !== $this->peekType()) {
                $arguments[] = $this->parseExpression();
                while (',' === $this->peekType()) {
                    ++$this->position;
                    $arguments[] = $this->parseExpression();
                }
            }
            $this->expect(')');

            return $this->callFunction($lower, $arguments);
        }

        // Variables are matched case-sensitively (a teacher writing V and v means two things),
        // constants case-insensitively (PI and pi are the same constant).
        if (\array_key_exists($name, $this->variables)) {
            return $this->variables[$name];
        }
        if (isset(self::CONSTANTS[$lower])) {
            return self::CONSTANTS[$lower];
        }

        throw new \InvalidArgumentException('unknown name');
    }

    /** @param list<float> $arguments */
    private function callFunction(string $name, array $arguments): float
    {
        $definition = self::FUNCTIONS[$name] ?? throw new \InvalidArgumentException('unknown function');
        [$arity, $callable] = $definition;

        $count = \count($arguments);
        $ok = match ($arity) {
            -1 => $count >= 1,
            -2 => 1 === $count || 2 === $count,
            default => $count === $arity,
        };
        if (!$ok) {
            throw new \InvalidArgumentException('wrong argument count');
        }

        // round()'s second argument is a precision, which PHP wants as an int.
        if ('round' === $name && 2 === $count) {
            return round($arguments[0], (int) $arguments[1]);
        }

        /** @var float|int $result */
        $result = $callable(...$arguments);

        return (float) $result;
    }

    private function peekType(): ?string
    {
        return $this->tokens[$this->position]['type'] ?? null;
    }

    /** @phpstan-impure advances $position */
    private function expect(string $type): void
    {
        if ($this->peekType() !== $type) {
            throw new \InvalidArgumentException('expected '.$type);
        }
        ++$this->position;
    }
}
