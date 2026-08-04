<?php

namespace App\Tests\Service;

use App\Service\EvaluationAverageCalculator;
use PHPUnit\Framework\TestCase;

/**
 * La moyenne générale du Carnet de notes, dans sa version pondérée par le coefficient de matière
 * (Topic::$coefficient). Ce sont des règles qu'aucun écran ne montre : l'étudiant ne voit qu'un
 * nombre, et rien à l'écran ne dit qu'une matière à coefficient 3 pèse trois fois plus, ni ce
 * qu'on fait d'une matière encore vide.
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
        // 8 à coefficient 3 et 16 à coefficient 1 : (8*3 + 16*1) / 4 = 10.
        self::assertSame(10.0, $this->calculator->overallAverage([
            ['average' => 8.0, 'coefficient' => 3.0],
            ['average' => 16.0, 'coefficient' => 1.0],
        ]));

        // Les mêmes moyennes à coefficients égaux donnent la moyenne simple - le coefficient est
        // donc bien ce qui déplace le résultat, et rien d'autre.
        self::assertSame(12.0, $this->calculator->overallAverage([
            ['average' => 8.0, 'coefficient' => 1.0],
            ['average' => 16.0, 'coefficient' => 1.0],
        ]));
    }

    public function testDecimalCoefficientsAreHonoured(): void
    {
        // Une matière d'appoint à 0.5 : (10*0.5 + 16*1) / 1.5 = 14.
        self::assertSame(14.0, $this->calculator->overallAverage([
            ['average' => 10.0, 'coefficient' => 0.5],
            ['average' => 16.0, 'coefficient' => 1.0],
        ]));
    }

    public function testSubjectWithoutAnyGradeIsIgnoredCoefficientIncluded(): void
    {
        // Une matière pas encore notée ne doit pas compter comme un zéro, ni consommer son
        // coefficient au dénominateur : le résultat est celui de la seule matière notée.
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
        // Un coefficient nul neutralise la matière au lieu de faire exploser la division.
        self::assertNull($this->calculator->overallAverage([
            ['average' => 12.0, 'coefficient' => 0.0],
        ]));
    }
}
