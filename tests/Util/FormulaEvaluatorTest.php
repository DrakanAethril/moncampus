<?php

declare(strict_types=1);

namespace App\Tests\Util;

use App\Util\FormulaEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic a "calculée" question's answer is written in. Hand-parsed rather than eval()'d
 * because the formula is teacher input evaluated on the server - see the class docblock - so the
 * tests that matter most are the ones pinning what the grammar *refuses*.
 */
class FormulaEvaluatorTest extends TestCase
{
    public function testArithmeticFollowsPrecedence(): void
    {
        self::assertSame(14.0, FormulaEvaluator::evaluate('2 + 3 * 4'));
        self::assertSame(20.0, FormulaEvaluator::evaluate('(2 + 3) * 4'));
        self::assertSame(1.0, FormulaEvaluator::evaluate('7 % 3'));
        self::assertSame(-6.0, FormulaEvaluator::evaluate('2 - 4 * 2'));
    }

    public function testPowerIsRightAssociative(): void
    {
        // 2^(3^2) = 512, the way it is written on paper - not (2^3)^2 = 64.
        self::assertSame(512.0, FormulaEvaluator::evaluate('2^3^2'));
    }

    public function testUnaryMinusApplies(): void
    {
        self::assertSame(-5.0, FormulaEvaluator::evaluate('-5'));
        self::assertSame(5.0, FormulaEvaluator::evaluate('--5'));
        self::assertSame(-8.0, FormulaEvaluator::evaluate('-2^3'));
    }

    public function testVariablesAreSubstituted(): void
    {
        self::assertSame(240.0, FormulaEvaluator::evaluate('v * t', ['v' => 120.0, 't' => 2.0]));
    }

    public function testVariablesAreCaseSensitiveButConstantsAreNot(): void
    {
        // A teacher writing V and v means two different quantities; PI and pi are one constant.
        self::assertNull(FormulaEvaluator::evaluate('V', ['v' => 1.0]));
        self::assertEqualsWithDelta(\M_PI, FormulaEvaluator::evaluate('PI'), 1e-9);
        self::assertEqualsWithDelta(\M_PI, FormulaEvaluator::evaluate('pi'), 1e-9);
    }

    public function testAVariableShadowsAConstantOfTheSameName(): void
    {
        // Someone will name a variable "e" for a thickness. Their own value has to win.
        self::assertSame(3.0, FormulaEvaluator::evaluate('e', ['e' => 3.0]));
    }

    public function testFunctions(): void
    {
        self::assertSame(4.0, FormulaEvaluator::evaluate('sqrt(16)'));
        self::assertSame(3.14, FormulaEvaluator::evaluate('round(3.14159, 2)'));
        self::assertSame(3.0, FormulaEvaluator::evaluate('round(3.4)'));
        self::assertSame(2.0, FormulaEvaluator::evaluate('min(5, 2, 9)'));
        self::assertSame(9.0, FormulaEvaluator::evaluate('max(5, 2, 9)'));
        self::assertSame(8.0, FormulaEvaluator::evaluate('pow(2, 3)'));
        self::assertSame(5.0, FormulaEvaluator::evaluate('abs(-5)'));
        self::assertEqualsWithDelta(2.0, FormulaEvaluator::evaluate('log(100, 10)'), 1e-9);
    }

    public function testARealisticPhysicsFormula(): void
    {
        // v = sqrt(2 g h)
        self::assertEqualsWithDelta(
            19.809,
            (float) FormulaEvaluator::evaluate('sqrt(2 * g * h)', ['g' => 9.81, 'h' => 20.0]),
            0.001,
        );
    }

    // --- what it refuses ---

    public function testDivisionByZeroIsNotAnAnswer(): void
    {
        self::assertNull(FormulaEvaluator::evaluate('1 / 0'));
        self::assertNull(FormulaEvaluator::evaluate('1 / (3 - 3)'));
        self::assertNull(FormulaEvaluator::evaluate('5 % 0'));
    }

    public function testAnImpossibleResultIsNotAnAnswer(): void
    {
        // sqrt of a negative is NAN, which must never reach a student's mark.
        self::assertNull(FormulaEvaluator::evaluate('sqrt(-4)'));
    }

    public function testUnknownNamesFail(): void
    {
        self::assertNull(FormulaEvaluator::evaluate('v * t', ['v' => 2.0]));
        self::assertNull(FormulaEvaluator::evaluate('nosuchfunction(2)'));
    }

    public function testSyntaxErrorsFail(): void
    {
        foreach (['2 +', '2 3', '(2 + 3', '2 + 3)', '', '   ', '*2', 'round()'] as $formula) {
            self::assertNull(FormulaEvaluator::evaluate($formula), sprintf('"%s" should not evaluate', $formula));
        }
    }

    public function testWrongArgumentCountsFail(): void
    {
        self::assertNull(FormulaEvaluator::evaluate('sqrt(1, 2)'));
        self::assertNull(FormulaEvaluator::evaluate('pow(2)'));
        self::assertNull(FormulaEvaluator::evaluate('round(1, 2, 3)'));
    }

    /**
     * The reason this class exists: nothing outside plain arithmetic is even expressible. These are
     * not "unsupported syntax" so much as the attack surface eval() would have opened.
     */
    public function testCodeCannotBeSmuggledThroughAFormula(): void
    {
        foreach ([
            'system("ls")',
            'phpinfo()',
            '`ls`',
            '$x',
            'file_get_contents("/etc/passwd")',
            '1; echo 1',
            'a->b',
            '"string"',
            '2 ?? 3',
        ] as $formula) {
            self::assertNull(FormulaEvaluator::evaluate($formula), sprintf('"%s" must not evaluate', $formula));
        }
    }

    // --- the editor's helpers ---

    public function testVariableNamesSkipFunctionsAndConstants(): void
    {
        self::assertSame(['g', 'h'], FormulaEvaluator::variableNames('sqrt(2 * g * h) + pi'));
        self::assertSame(['v', 't'], FormulaEvaluator::variableNames('v * t + v'));
        self::assertSame([], FormulaEvaluator::variableNames('2 + 2'));
    }

    public function testSyntaxCheckIgnoresValues(): void
    {
        // "a / b" is valid even though b could be zero - the check is about the writing, and
        // probing with zeros would report every division as broken.
        self::assertTrue(FormulaEvaluator::isSyntaxValid('a / b'));
        self::assertTrue(FormulaEvaluator::isSyntaxValid('round(m / (t * t), 2)'));
        self::assertFalse(FormulaEvaluator::isSyntaxValid('2 +'));
        self::assertFalse(FormulaEvaluator::isSyntaxValid('système(2)'));
    }
}
