<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\QuizInstanceQuestion;
use App\Enum\QuestionType;
use App\Service\QuizAnswerChecker;
use App\Service\QuizAttemptGrader;
use PHPUnit\Framework\TestCase;

/**
 * Grading rules of the numeric pair (2026-08-11): `numerique` (a number within a tolerance,
 * optionally with its unit) and `calculee` (the same, over variables drawn per student and an
 * expected value given by a formula). Same single-rule setup as the families before it:
 * QuizAnswerChecker holds the verdict, QuizAttemptGrader turns it into points, and both the real
 * passation and the teacher's "Tester" preview go through them.
 */
class QuizNumericGradingTest extends TestCase
{
    private QuizAnswerChecker $checker;
    private QuizAttemptGrader $grader;

    protected function setUp(): void
    {
        $this->checker = new QuizAnswerChecker();
        $this->grader = new QuizAttemptGrader($this->checker);
    }

    // --- numérique: tolerance ---

    public function testAPercentToleranceAcceptsEitherSideAndRejectsBeyond(): void
    {
        $question = $this->numericQuestion(240.0, tolerance: 2.0);

        self::assertTrue($this->isCorrect($question, 240.0));
        self::assertTrue($this->isCorrect($question, 244.8), '+2 % exactly is inside');
        self::assertTrue($this->isCorrect($question, 235.2), '-2 % exactly is inside');
        self::assertFalse($this->isCorrect($question, 245.0));
        self::assertFalse($this->isCorrect($question, 235.0));
    }

    public function testAnAbsoluteToleranceIsAFixedMargin(): void
    {
        $question = $this->numericQuestion(20.0, tolerance: 0.5, mode: 'absolute');

        self::assertTrue($this->isCorrect($question, 20.5));
        self::assertTrue($this->isCorrect($question, 19.5));
        self::assertFalse($this->isCorrect($question, 20.6));
    }

    public function testAPercentToleranceOnANegativeExpectedValueUsesItsMagnitude(): void
    {
        // -10 °C ± 10 % has to mean ±1 °C, not a margin that flips sign and rejects everything.
        $question = $this->numericQuestion(-10.0, tolerance: 10.0);

        self::assertTrue($this->isCorrect($question, -10.9));
        self::assertFalse($this->isCorrect($question, -11.1));
    }

    public function testAPercentToleranceOnZeroAcceptsOnlyZero(): void
    {
        // Documented and deliberate: 2 % of 0 is 0, and reading the 2 as an absolute margin would
        // accept 1.9 on a question whose answer might be in millimetres.
        $question = $this->numericQuestion(0.0, tolerance: 2.0);

        self::assertTrue($this->isCorrect($question, 0.0));
        self::assertFalse($this->isCorrect($question, 0.05));
    }

    public function testAnUnansweredQuestionIsWrong(): void
    {
        $question = $this->numericQuestion(240.0);

        self::assertFalse($this->isCorrect($question, null));
    }

    public function testAQuestionWithNoExpectedValueIsNeverRight(): void
    {
        // Same guard as an answerless QCM: an unfinished question must not hand out points.
        $question = $this->numericQuestion(null);

        self::assertFalse($this->isCorrect($question, 0.0));
        self::assertFalse($this->isCorrect($question, 42.0));
    }

    // --- units ---

    public function testAUnitIsIgnoredUnlessTheTeacherAskedForIt(): void
    {
        $question = $this->numericQuestion(240.0, unit: 'km');

        self::assertTrue($this->isCorrect($question, 240.0, 'km'));
        self::assertTrue($this->isCorrect($question, 240.0, null), 'the field already shows the unit');
        self::assertTrue($this->isCorrect($question, 240.0, 'bananes'), 'noise after the number is not the answer');
    }

    public function testARequiredUnitBecomesPartOfTheAnswer(): void
    {
        $question = $this->numericQuestion(240.0, unit: 'km', unitRequired: true);

        self::assertTrue($this->isCorrect($question, 240.0, 'km'));
        self::assertTrue($this->isCorrect($question, 240.0, 'KM'), 'capitalisation is not physics');
        self::assertFalse($this->isCorrect($question, 240.0, 'm'));
        self::assertFalse($this->isCorrect($question, 240.0, null));
    }

    public function testAQuestionWithoutAUnitCanNeverRequireOne(): void
    {
        $question = $this->numericQuestion(240.0, unitRequired: true);

        self::assertFalse($question->isNumericUnitRequired());
        self::assertTrue($this->isCorrect($question, 240.0, null));
    }

    // --- calculée ---

    public function testTheExpectedValueComesFromTheFormulaAndTheStudentOwnVariables(): void
    {
        $question = $this->calculatedQuestion('v * t');

        self::assertSame(240.0, $this->checker->expectedNumericValue($question, ['v' => 120.0, 't' => 2.0]));
        self::assertSame(300.0, $this->checker->expectedNumericValue($question, ['v' => 100.0, 't' => 3.0]));
    }

    public function testTwoStudentsWithDifferentDrawsAreGradedAgainstTheirOwnAnswer(): void
    {
        $question = $this->calculatedQuestion('v * t', tolerance: 2.0);

        self::assertTrue($this->isCorrect($question, 240.0, null, ['v' => 120.0, 't' => 2.0]));
        // The same 240 is wrong for the student who was asked about 100 km/h for 3 h.
        self::assertFalse($this->isCorrect($question, 240.0, null, ['v' => 100.0, 't' => 3.0]));
        self::assertTrue($this->isCorrect($question, 300.0, null, ['v' => 100.0, 't' => 3.0]));
    }

    public function testAFormulaThatCannotBeEvaluatedIsNeverRight(): void
    {
        // A missing variable, a broken formula and a division by zero all land here, and all mean
        // the same thing to a student: nobody can get this right.
        $broken = $this->calculatedQuestion('v * t');
        self::assertFalse($this->isCorrect($broken, 240.0, null, ['v' => 120.0]));

        $nonsense = $this->calculatedQuestion('v *');
        self::assertFalse($this->isCorrect($nonsense, 0.0, null, ['v' => 1.0]));

        $divideByZero = $this->calculatedQuestion('v / t');
        self::assertFalse($this->isCorrect($divideByZero, 0.0, null, ['v' => 1.0, 't' => 0.0]));
    }

    public function testToleranceScalesWithEachStudentOwnExpectedValue(): void
    {
        // 2 % of 240 is 4.8; 2 % of 30 is 0.6. The same question, two students, two margins.
        $question = $this->calculatedQuestion('v * t', tolerance: 2.0);

        self::assertTrue($this->isCorrect($question, 244.0, null, ['v' => 120.0, 't' => 2.0]));
        self::assertFalse($this->isCorrect($question, 34.0, null, ['v' => 15.0, 't' => 2.0]));
    }

    // --- points ---

    public function testANumericQuestionScoresAllOrNothingAtItsOwnBareme(): void
    {
        $question = $this->numericQuestion(240.0, tolerance: 2.0);
        $question->setPoints(3.0);

        self::assertSame(3.0, $this->score($question, 241.0));
        self::assertSame(0.0, $this->score($question, 300.0));
        self::assertSame(0.0, $this->score($question, null));
    }

    // --- config reading ---

    public function testVariablesAreReadDefensivelyFromStoredJson(): void
    {
        $question = $this->calculatedQuestion('a');
        $question->setNumericConfig([
            'formula' => 'a',
            'variables' => [
                ['name' => 'a', 'min' => 10, 'max' => 1],       // written backwards: read as 1..10
                ['name' => 'b', 'min' => 1],                     // no max: unusable, dropped
                ['name' => '', 'min' => 1, 'max' => 2],          // no name: dropped
                ['name' => 'a', 'min' => 5, 'max' => 6],         // duplicate: first wins
                ['name' => 'c', 'min' => 1, 'max' => 2, 'step' => 0, 'decimals' => 2],
                'not an array',
            ],
        ]);

        $variables = $question->getNumericVariables();
        self::assertSame(['a', 'c'], array_column($variables, 'name'));
        self::assertSame(1.0, $variables[0]['min']);
        self::assertSame(10.0, $variables[0]['max']);
        // A zero step would divide by zero when drawing - it means "the finest the decimals allow".
        self::assertSame(0.01, $variables[1]['step']);
    }

    public function testATotallyBrokenConfigIsReadAsAnEmptyQuestion(): void
    {
        $question = (new \ReflectionClass(QuizInstanceQuestion::class))->newInstanceWithoutConstructor();
        $question->setType(QuestionType::Calculee);
        $question->setNumericConfig(['variables' => 'nope', 'tolerance' => 'x', 'formula' => 42]);

        self::assertSame([], $question->getNumericVariables());
        self::assertSame(2.0, $question->getNumericTolerance(), 'falls back to the 2 % default');
        self::assertSame('42', $question->getNumericFormula(), 'a scalar formula is read as written');
        self::assertNull($question->getNumericAnswer());
    }

    public function testStatementVariablesComeFromTheLabel(): void
    {
        $question = $this->calculatedQuestion('v * t');
        $question->setLabel('Un train roule à {v} km/h pendant {t} h. Quelle distance ?');

        self::assertSame(['v', 't'], $question->getNumericStatementVariables());
    }

    // --- enum plumbing ---

    public function testTheNumericTypesAreExcludedFromLiveAndFromAnswerRows(): void
    {
        foreach ([QuestionType::Numerique, QuestionType::Calculee] as $type) {
            self::assertFalse($type->isAvailableInLiveContest());
            self::assertFalse($type->usesAnswerRows());
            self::assertTrue($type->usesNumericConfig());
        }

        self::assertTrue(QuestionType::Calculee->usesFormula());
        self::assertFalse(QuestionType::Numerique->usesFormula(), 'a numérique has one written value');
        self::assertFalse(QuestionType::Qcm->usesNumericConfig());
    }

    /** @param array<string, float> $variables */
    private function isCorrect(QuizInstanceQuestion $question, ?float $value, ?string $unit = null, array $variables = []): bool
    {
        return $this->checker->isCorrect($question, [], [], [], [], [], $value, $unit, $variables);
    }

    /** @param array<string, float> $variables */
    private function score(QuizInstanceQuestion $question, ?float $value, ?string $unit = null, array $variables = []): float
    {
        return $this->grader->score($question, [], [], [], [], $value, $unit, $variables);
    }

    private function numericQuestion(?float $answer, float $tolerance = 2.0, string $mode = 'percent', ?string $unit = null, bool $unitRequired = false): QuizInstanceQuestion
    {
        $question = (new \ReflectionClass(QuizInstanceQuestion::class))->newInstanceWithoutConstructor();
        $question->setType(QuestionType::Numerique);
        $question->setLabel('Quelle distance ?');
        $question->setNumericConfig([
            'answer' => $answer,
            'tolerance' => $tolerance,
            'toleranceMode' => $mode,
            'unit' => $unit,
            'unitRequired' => $unitRequired,
        ]);

        return $question;
    }

    private function calculatedQuestion(string $formula, float $tolerance = 2.0): QuizInstanceQuestion
    {
        $question = (new \ReflectionClass(QuizInstanceQuestion::class))->newInstanceWithoutConstructor();
        $question->setType(QuestionType::Calculee);
        $question->setLabel('Un train roule à {v} km/h pendant {t} h.');
        $question->setNumericConfig(['formula' => $formula, 'tolerance' => $tolerance, 'toleranceMode' => 'percent']);

        return $question;
    }
}
