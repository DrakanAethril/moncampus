<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\EvaluationAverageCalculator;
use PHPUnit\Framework\TestCase;

/**
 * The overall average of the Carnet de notes, in its version weighted by the matière coefficient
 * (Topic::$coefficient). These are rules no screen shows: the student only sees a number, and nothing
 * on screen says that a matière with coefficient 3 weighs three times as much, nor what is done with
 * a matière that is still empty.
 */
class EvaluationAverageCalculatorTest extends TestCase
{
    private EvaluationAverageCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new EvaluationAverageCalculator();
    }

    public function testSubjectCoefficientWeightsTheGeneralAverage(): void
    {
        // 8 at coefficient 3 and 16 at coefficient 1: (8*3 + 16*1) / 4 = 10.
        self::assertSame(10.0, $this->calculator->overallAverage([
            ['average' => 8.0, 'coefficient' => 3.0],
            ['average' => 16.0, 'coefficient' => 1.0],
        ]));

        // The same averages at equal coefficients give the plain average - so the coefficient really
        // is what moves the result, and nothing else.
        self::assertSame(12.0, $this->calculator->overallAverage([
            ['average' => 8.0, 'coefficient' => 1.0],
            ['average' => 16.0, 'coefficient' => 1.0],
        ]));
    }

    public function testDecimalCoefficientsAreHonoured(): void
    {
        // A minor matière at 0.5: (10*0.5 + 16*1) / 1.5 = 14.
        self::assertSame(14.0, $this->calculator->overallAverage([
            ['average' => 10.0, 'coefficient' => 0.5],
            ['average' => 16.0, 'coefficient' => 1.0],
        ]));
    }

    public function testSubjectWithoutAnyGradeIsIgnoredCoefficientIncluded(): void
    {
        // A matière not yet graded must not count as a zero, nor consume its coefficient in the
        // denominator: the result is that of the only graded matière.
        self::assertSame(15.0, $this->calculator->overallAverage([
            ['average' => 15.0, 'coefficient' => 1.0],
            ['average' => null, 'coefficient' => 9.0],
        ]));
    }

    public function testNoCountableSubjectYieldsNull(): void
    {
        self::assertNull($this->calculator->overallAverage([]));
        self::assertNull($this->calculator->overallAverage([
            ['average' => null, 'coefficient' => 1.0],
        ]));
        // A zero coefficient neutralises the matière instead of blowing up the division.
        self::assertNull($this->calculator->overallAverage([
            ['average' => 12.0, 'coefficient' => 0.0],
        ]));
    }
}
