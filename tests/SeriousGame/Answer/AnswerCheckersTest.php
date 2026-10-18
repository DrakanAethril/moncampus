<?php

declare(strict_types=1);

namespace App\Tests\SeriousGame\Answer;

use App\SeriousGame\Answer\AnswerCheckers;
use App\SeriousGame\Answer\AnswerSpec;
use App\SeriousGame\Answer\AnswerType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The writings each answer type accepts (design/validated/serious-games.md §5.2): one case per
 * accepted writing, and the near-misses that must not pass.
 */
class AnswerCheckersTest extends TestCase
{
    /** @return iterable<string, array{AnswerType, mixed, string, bool, 3?: array<string, mixed>}> */
    public static function writings(): iterable
    {
        // number
        yield 'decimal comma' => [AnswerType::Number, 3.5, '3,5', true];
        yield 'decimal point' => [AnswerType::Number, 3.5, '3.5', true];
        yield 'fraction for a number' => [AnswerType::Number, 3.5, '7/2', true];
        yield 'real minus sign' => [AnswerType::Number, -3, '−3', true];
        yield 'percent sign' => [AnswerType::Number, 15, '15 %', true];
        yield 'plus sign' => [AnswerType::Number, 25, '+25', true];
        yield 'une baisse de' => [AnswerType::Number, -20, 'une baisse de 20 %', true];
        yield 'x = prefix' => [AnswerType::Number, 4, 'x = 4', true];
        yield 'thin space thousands' => [AnswerType::Number, 1234, "1\u{202F}234", true];
        yield 'euro sign' => [AnswerType::Number, 12, '12 €', true];
        yield 'within tolerance' => [AnswerType::Number, 33.333, '≈ 33,3', true, ['tolerance' => 0.15]];
        yield 'outside tolerance' => [AnswerType::Number, 33.333, '33', false, ['tolerance' => 0.15]];
        yield 'a wrong number' => [AnswerType::Number, 3.5, '3,6', false];
        yield 'not a number' => [AnswerType::Number, 3.5, 'trois', false];
        // fraction
        yield 'equivalent fraction' => [AnswerType::Fraction, 0.75, '6/8', true];
        yield 'reduced fraction' => [AnswerType::Fraction, 0.75, '3/4', true];
        yield 'fraction as decimal' => [AnswerType::Fraction, 0.75, '0,75', true];
        yield 'negative fraction' => [AnswerType::Fraction, -0.75, '−3/4', true];
        // power: values are natural logarithms, « a » reads as 1.7
        yield 'power of a letter' => [AnswerType::Power, 6 * log(1.7), 'a^6', true];
        yield 'power of a number' => [AnswerType::Power, 7 * log(2), '2^7', true];
        yield 'power computed' => [AnswerType::Power, 7 * log(2), '128', true];
        yield 'negative exponent' => [AnswerType::Power, -3 * log(10), '10^-3', true];
        yield 'wrong exponent' => [AnswerType::Power, 6 * log(1.7), 'a^5', false];
        // expression
        yield 'implicit product' => [AnswerType::Expression, '2*x+2', '2(x+1)', true];
        yield 'unicode square' => [AnswerType::Expression, '(1)*x^2+(0)*x^1+(-1)*x^0', 'x²−1', true];
        yield 'caret square' => [AnswerType::Expression, '(1)*x^2+(0)*x^1+(-1)*x^0', 'x^2 - 1', true];
        yield 'times sign' => [AnswerType::Expression, '6*x', '6 × x', true];
        yield 'a different expression' => [AnswerType::Expression, '2*x+2', '2x+1', false];
        yield 'polynomial' => [AnswerType::Polynomial, '(2)*x^2+(3)*x^1+(0)*x^0', '2x² + 3x', true, ['form' => 'developed']];
        // set
        yield 'two values' => [AnswerType::Set, [-3, 2], '-3 ; 2', true];
        yield 'braces and order' => [AnswerType::Set, [-3, 2], 'S = {2 ; −3}', true];
        yield 'x = … ou x = …' => [AnswerType::Set, [-3, 2], 'x=2 ou x=-3', true];
        yield 'empty set' => [AnswerType::Set, [], '∅', true];
        yield 'aucune solution' => [AnswerType::Set, [], 'aucune solution', true];
        yield 'square roots' => [AnswerType::Set, [-sqrt(5), sqrt(5)], '-√5 ; √5', true];
        yield 'fractions in a set' => [AnswerType::Set, [-1.5, 0.5], '-3/2 ; 1/2', true];
        yield 'one value missing' => [AnswerType::Set, [-3, 2], '2', false];
        // interval
        yield 'interval brackets' => [AnswerType::Interval, ['lo' => -\INF, 'hi' => 4, 'lc' => false, 'hc' => true], ']−∞ ; 4]', true];
        yield 'inequality' => [AnswerType::Interval, ['lo' => -\INF, 'hi' => 4, 'lc' => false, 'hc' => true], 'x ≤ 4', true];
        yield 'double inequality' => [AnswerType::Interval, ['lo' => -2, 'hi' => 1, 'lc' => true, 'hc' => true], '-2 ≤ x ≤ 1', true];
        yield 'comma as separator' => [AnswerType::Interval, ['lo' => -2, 'hi' => 1, 'lc' => true, 'hc' => true], '[−2 , 1]', true];
        yield 'inf spelled out' => [AnswerType::Interval, ['lo' => -\INF, 'hi' => 4, 'lc' => false, 'hc' => true], ']-inf;4]', true];
        yield 'reversed inequality' => [AnswerType::Interval, ['lo' => 3, 'hi' => \INF, 'lc' => false, 'hc' => false], '3 < x', true];
        yield 'S = prefix' => [AnswerType::Interval, ['lo' => 3, 'hi' => \INF, 'lc' => true, 'hc' => false], 'S = [3 ; +∞[', true];
        yield 'wrong closure' => [AnswerType::Interval, ['lo' => -2, 'hi' => 1, 'lc' => true, 'hc' => true], ']−2 ; 1]', false];
        yield 'any closure accepted' => [AnswerType::Interval, ['lo' => -2, 'hi' => 1, 'lc' => true, 'hc' => true], ']−2 ; 1[', true, ['anyClosure' => true]];
        // scientific
        yield 'times ten caret' => [AnswerType::Scientific, ['m' => 3.2, 'e' => -4], '3,2 × 10^-4', true];
        yield 'e notation' => [AnswerType::Scientific, ['m' => 3.2, 'e' => -4], '3.2e-4', true];
        yield 'bracketed exponent' => [AnswerType::Scientific, ['m' => 3.2, 'e' => -4], '3,2*10^(-4)', true];
        yield 'power of ten alone' => [AnswerType::Scientific, ['m' => 1, 'e' => 5], '10^5', true];
        yield 'another exponent' => [AnswerType::Scientific, ['m' => 3.2, 'e' => -4], '3,2 × 10^-3', false];
        // formula
        yield 'right-hand side' => [AnswerType::Formula, 'U/I', 'U/I', true, ['vars' => ['U', 'R', 'I'], 'target' => 'R']];
        yield 'with its left-hand side' => [AnswerType::Formula, 'U/I', 'R = U/I', true, ['vars' => ['U', 'R', 'I'], 'target' => 'R']];
        yield 'division sign' => [AnswerType::Formula, 'U/I', 'U ÷ I', true, ['vars' => ['U', 'R', 'I'], 'target' => 'R']];
        yield 'alias of v₀' => [AnswerType::Formula, 'v-a*t', 'v0 = v - a×t', true, ['vars' => ['v', 'v₀', 'a', 't'], 'target' => 'v₀']];
        yield 'equivalent rewriting' => [AnswerType::Formula, '2*𝒜/h', '2A/h', true, ['vars' => ['𝒜', 'b', 'h'], 'target' => 'b']];
        yield 'a wrong formula' => [AnswerType::Formula, 'U/I', 'U*I', false, ['vars' => ['U', 'R', 'I'], 'target' => 'R']];
        // text
        yield 'text ignores case' => [AnswerType::Text, 'Paris', ' paris ', true];
        yield 'text differs' => [AnswerType::Text, 'Paris', 'Lyon', false];
        // latin
        yield 'latin form' => [AnswerType::Latin, 'rosam', 'rosam', true];
        yield 'latin with macrons' => [AnswerType::Latin, 'rosa', 'rosā', true];
        yield 'latin capital' => [AnswerType::Latin, 'domini', ' Domini ', true];
        yield 'latin j for i' => [AnswerType::Latin, 'cuius', 'cujus', true];
        yield 'latin alternative' => [AnswerType::Latin, ['cives', 'civis'], 'civis', true];
        yield 'latin wrong ending' => [AnswerType::Latin, 'rosam', 'rosum', false];
        yield 'latin with a digit' => [AnswerType::Latin, 'rosam', 'rosam2', false];
        // forms (a table to fill)
        yield 'table in fields' => [AnswerType::Forms, ['rosa', 'rosa', 'rosam'], 'rosa|rosa|rosām', true, ['labels' => ['N', 'V', 'Acc']]];
        yield 'table on one line' => [AnswerType::Forms, ['rosa', 'rosa', 'rosam'], 'rosa ; rosa ; rosam', true, ['labels' => ['N', 'V', 'Acc']]];
        yield 'table with an alternative' => [AnswerType::Forms, ['cives', ['cives', 'civis']], 'cives · civis', true, ['labels' => ['N', 'Acc']]];
        yield 'table one cell wrong' => [AnswerType::Forms, ['rosa', 'rosa', 'rosam'], 'rosa|rosa|rosum', false, ['labels' => ['N', 'V', 'Acc']]];
        yield 'table one cell missing' => [AnswerType::Forms, ['rosa', 'rosa', 'rosam'], 'rosa|rosa', false, ['labels' => ['N', 'V', 'Acc']]];
        // choice
        yield 'choice' => [AnswerType::Choice, 'génitif singulier', 'génitif singulier', true];
        yield 'choice without accents' => [AnswerType::Choice, 'génitif singulier', 'Genitif singulier', true];
        yield 'another choice' => [AnswerType::Choice, 'génitif singulier', 'datif singulier', false];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('writings')]
    public function testAWriting(AnswerType $type, mixed $expected, string $input, bool $accepted, array $options = []): void
    {
        $spec = AnswerSpec::fromArray(['type' => $type->value] + $options);
        $checker = AnswerCheckers::for($type);
        $read = $checker->read($input, $spec);

        $ok = null !== $read && $checker->same($read, $checker->value($expected, $spec), $spec, $spec->tolerance);

        self::assertSame($accepted, $ok, $type->value.' « '.$input.' »');
    }

    public function testEveryTypeHasItsChecker(): void
    {
        foreach (AnswerType::cases() as $type) {
            self::assertTrue(AnswerCheckers::for($type)->supports($type), $type->value);
        }
    }
}
