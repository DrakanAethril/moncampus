<?php

declare(strict_types=1);

namespace App\SeriousGame\Answer;

use App\SeriousGame\Math\MathExpression;

/**
 * A formula in several letters - « isoler une variable » (design/validated/serious-games.md §5.2):
 * « U/I », « R = U/I », « U ÷ I », « v0 = v − a×t ». Each letter is replaced by a value and two
 * formulas are the same answer when they agree on four sets of values. A leading « target = » is
 * dropped; the letters' usual spellings are accepted (v₀ as v0, 𝒜 as A, ρ as rho), and the case is
 * free whenever it does not tell two letters apart.
 */
final class FormulaChecker implements AnswerChecker
{
    private const array ALIASES = ['v₀' => ['v₀', 'v0', 'v_0', 'vo'], '𝒜' => ['𝒜', 'A', 'aire'], 'ρ' => ['ρ', 'rho']];

    private const array SETS = [[1.7, 2.3, 0.6, 3.1, 1.25], [2.9, 0.8, 1.9, 0.45, 2.2], [0.7, 3.6, 2.4, 1.15, 0.9], [4.1, 1.3, 0.55, 2.7, 3.3]];

    public function supports(AnswerType $type): bool
    {
        return AnswerType::Formula === $type;
    }

    public function value(mixed $raw, AnswerSpec $spec): string
    {
        if (!\is_string($raw) || null === self::compile($raw, $spec)) {
            throw new \InvalidArgumentException('une formule lisible dans les lettres déclarées était attendue');
        }

        return $raw;
    }

    public function read(string $input, AnswerSpec $spec): ?string
    {
        return null === self::compile($input, $spec) ? null : $input;
    }

    public function same(mixed $a, mixed $b, AnswerSpec $spec, ?float $tolerance = null): bool
    {
        if (!\is_string($a) || !\is_string($b)) {
            return false;
        }

        $fa = self::compile($a, $spec);
        $fb = self::compile($b, $spec);
        if (null === $fa || null === $fb) {
            return false;
        }

        foreach (self::SETS as $values) {
            $env = [];
            foreach ($spec->vars as $k => $name) {
                $env[$name] = $values[$k % \count($values)];
            }
            $u = $fa($env);
            $w = $fb($env);
            if (!is_finite($u) || !is_finite($w) || abs($u - $w) > 1e-7 * max(1, abs($u))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return (\Closure(array<string, float>): float)|null
     */
    private static function compile(string $source, AnswerSpec $spec): ?\Closure
    {
        $s = (string) preg_replace('/[\s\x{00A0}\x{202F}]/u', '', strip_tags($source));
        $vars = $spec->vars;
        $target = (string) $spec->target;

        $names = [];
        foreach ($vars as $name) {
            foreach (self::ALIASES[$name] ?? [$name] as $alias) {
                $names[] = [$alias, $name];
            }
        }

        $lows = array_map(mb_strtolower(...), $vars);
        $caseFree = \count(array_unique($lows)) === \count($lows);
        if ($caseFree) {
            $extra = [];
            foreach ($names as [$spelling, $name]) {
                if (mb_strtolower($spelling) !== $spelling) {
                    $extra[] = [mb_strtolower($spelling), $name];
                }
                if (mb_strtoupper($spelling) !== $spelling && 1 === mb_strlen($spelling)) {
                    $extra[] = [mb_strtoupper($spelling), $name];
                }
            }
            $names = [...$names, ...$extra];
        }
        usort($names, static fn (array $p, array $q): int => mb_strlen($q[0]) <=> mb_strlen($p[0]));

        $targets = self::ALIASES[$target] ?? [$target];
        if ($caseFree) {
            $targets = [...$targets, mb_strtolower($target), mb_strtoupper($target)];
        }
        foreach ($targets as $spelling) {
            if (str_starts_with($s, $spelling.'=')) {
                $s = mb_substr($s, mb_strlen($spelling) + 1);
            }
        }

        if (str_contains($s, '=')) {
            return null;
        }

        $tokens = [];
        $chars = mb_str_split($s);
        $i = 0;
        $length = \count($chars);
        while ($i < $length) {
            $hit = null;
            foreach ($names as $pair) {
                $len = mb_strlen($pair[0]);
                if (implode('', \array_slice($chars, $i, $len)) === $pair[0]) {
                    $hit = $pair;
                    break;
                }
            }

            if (null !== $hit) {
                $tokens[] = ['v' => $hit[1]];
                $i += mb_strlen($hit[0]);

                continue;
            }

            $c = $chars[$i];
            if ('×' !== $c && '÷' !== $c && preg_match('/[A-Za-zÀ-ÿ𝒜ρ₀]/u', $c)) {
                return null;
            }

            $tokens[] = ['t' => $c];
            ++$i;
        }

        return static function (array $env) use ($tokens): float {
            $expression = '';
            foreach ($tokens as $token) {
                $expression .= isset($token['v']) ? '('.($env[$token['v']] ?? 'NaN').')' : $token['t'];
            }

            return MathExpression::evaluate($expression);
        };
    }
}
