<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\QuizQuestion;
use App\Enum\QuestionType;
use App\Service\QuizAnswerChecker;
use App\Service\VideoCueGrader;
use PHPUnit\Framework\TestCase;

/**
 * The reading of what a student's overlay posts back, for the twelve question types (créas 5B,
 * screen 4). The verdict itself is QuizAnswerChecker's - the point of the video layer is that it
 * borrows the library's questions AND the library's grading, so nothing here re-decides what a
 * right answer is.
 *
 * What is tested is the boundary: a JSON body typed by nobody, bounded by the question's own
 * config. Everything it cannot make sense of has to read as "not answered" rather than as a crash
 * or - far worse - as a free point.
 */
final class VideoCueGraderTest extends TestCase
{
    private VideoCueGrader $grader;

    protected function setUp(): void
    {
        $this->grader = new VideoCueGrader(new QuizAnswerChecker());
    }

    // --- the answer-row types ---

    public function testASingleChoiceIsReadFromTheAnswersKey(): void
    {
        $question = $this->question(QuestionType::Qcm);
        $answers = [
            ['id' => 11, 'correct' => false, 'orderIndex' => 0],
            ['id' => 12, 'correct' => true, 'orderIndex' => 1],
        ];

        self::assertTrue($this->grader->isCorrect($question, $answers, ['answers' => [12]]));
        self::assertFalse($this->grader->isCorrect($question, $answers, ['answers' => [11]]));
    }

    public function testAnswerIdsArrivingAsStringsStillCount(): void
    {
        // JSON.stringify of a checkbox value gives "12", not 12 - a whole type of question would
        // grade wrong for everyone if this were left to PHP's loose reading.
        $question = $this->question(QuestionType::Qcm);
        $answers = [['id' => 12, 'correct' => true, 'orderIndex' => 0]];

        self::assertTrue($this->grader->isCorrect($question, $answers, ['answers' => ['12']]));
    }

    public function testAMissingAnswersKeyIsNotAnAnswer(): void
    {
        $question = $this->question(QuestionType::Qcm);
        $answers = [['id' => 12, 'correct' => true, 'orderIndex' => 0]];

        self::assertFalse($this->grader->isCorrect($question, $answers, []));
        self::assertFalse($this->grader->isCorrect($question, $answers, ['answers' => 'oui']));
    }

    public function testAnOrderQuestionKeepsTheSubmittedSequence(): void
    {
        $question = $this->question(QuestionType::Ordre);
        $answers = [
            ['id' => 21, 'correct' => false, 'orderIndex' => 0],
            ['id' => 22, 'correct' => false, 'orderIndex' => 1],
        ];

        self::assertTrue($this->grader->isCorrect($question, $answers, ['answers' => [21, 22]]));
        self::assertFalse($this->grader->isCorrect($question, $answers, ['answers' => [22, 21]]));
    }

    // --- the typed-answer types ---

    public function testBlanksAreReadInTextOrder(): void
    {
        $question = $this->question(QuestionType::TexteATrous);
        $question->setLabel('La méthode ... compile, la méthode ... exécute.');
        $question->setBlankAnswers([['prepare'], ['execute']]);

        self::assertTrue($this->grader->isCorrect($question, [], ['blanks' => ['prepare', 'execute']]));
        self::assertFalse($this->grader->isCorrect($question, [], ['blanks' => ['execute', 'prepare']]));
        self::assertFalse($this->grader->isCorrect($question, [], ['blanks' => ['prepare']]));
    }

    public function testAShortAnswerIsOneBlank(): void
    {
        $question = $this->question(QuestionType::ReponseCourte);
        $question->setLabel('Quel mode configure-t-on sur un lien inter-commutateurs ?');
        $question->setBlankAnswers([['trunk']]);

        self::assertTrue($this->grader->isCorrect($question, [], ['blanks' => ['trunk']]));
        self::assertFalse($this->grader->isCorrect($question, [], ['blanks' => ['access']]));
    }

    // --- the zone types ---

    public function testClickedZonesAreBoundedByTheQuestionsOwnZones(): void
    {
        $question = $this->question(QuestionType::Zone);
        $question->setZoneConfig(['kind' => 'code', 'language' => 'html', 'content' => '[[z1|<nav>]][[z2|</nav>]]', 'correct' => ['z2'], 'hint' => []]);

        self::assertTrue($this->grader->isCorrect($question, [], ['zones' => ['z2']]));
        self::assertFalse($this->grader->isCorrect($question, [], ['zones' => ['z1']]));
        // A zone id the support does not carry is dropped rather than counted - the same reading
        // the library's "Tester" tab applies. Dropping rather than refusing is deliberate: a
        // hand-written body gains nothing by adding ids nobody can click, and a stale support (the
        // teacher rewrote it between two viewings) must not mark an otherwise right answer wrong.
        self::assertTrue($this->grader->isCorrect($question, [], ['zones' => ['z2', 'z9']]));
    }

    public function testLegendePlacementsAreBoundedByZonesAndChoices(): void
    {
        $question = $this->question(QuestionType::Legende);
        $question->setZoneConfig(['kind' => 'code', 'language' => 'html', 'content' => '[[z1|<nav>]][[z2|</nav>]]', 'correct' => [], 'hint' => []]);

        self::assertTrue($this->grader->isCorrect($question, [], ['placements' => ['z1' => 'z1', 'z2' => 'z2']]));
        self::assertFalse($this->grader->isCorrect($question, [], ['placements' => ['z1' => 'z2', 'z2' => 'z1']]));
        self::assertFalse($this->grader->isCorrect($question, [], ['placements' => ['z1' => 'z1', 'z9' => 'z9']]));
    }

    public function testMatchingAssociationsAreBoundedByPairsAndChoices(): void
    {
        $question = $this->question(QuestionType::Apparier);
        $question->setMatchingConfig([
            'pairs' => [
                ['id' => 'p1', 'left' => 'France', 'right' => 'Paris'],
                ['id' => 'p2', 'left' => 'Italie', 'right' => 'Rome'],
            ],
            'distractors' => [],
        ]);

        self::assertTrue($this->grader->isCorrect($question, [], ['pairs' => ['p1' => 'p1', 'p2' => 'p2']]));
        self::assertFalse($this->grader->isCorrect($question, [], ['pairs' => ['p1' => 'p2', 'p2' => 'p1']]));
        self::assertFalse($this->grader->isCorrect($question, [], ['pairs' => ['p1' => 'p1']]));
    }

    // --- the numeric types ---

    public function testANumberIsReadTheWayTheRestOfTheAppReadsOne(): void
    {
        $question = $this->question(QuestionType::Numerique);
        $question->setNumericConfig(['answer' => 12.5, 'tolerance' => 0.0, 'toleranceMode' => 'absolute', 'unit' => null, 'unitRequired' => false]);

        // A French keyboard types a comma, and the field is free text.
        self::assertTrue($this->grader->isCorrect($question, [], ['numeric' => '12,5']));
        self::assertFalse($this->grader->isCorrect($question, [], ['numeric' => '13']));
        self::assertFalse($this->grader->isCorrect($question, [], ['numeric' => '']));
        self::assertFalse($this->grader->isCorrect($question, [], []));
    }

    public function testARequiredUnitIsReadOffTheSameField(): void
    {
        $question = $this->question(QuestionType::Numerique);
        $question->setNumericConfig(['answer' => 12.5, 'tolerance' => 0.0, 'toleranceMode' => 'absolute', 'unit' => 'A', 'unitRequired' => true]);

        self::assertTrue($this->grader->isCorrect($question, [], ['numeric' => '12,5 A']));
        self::assertFalse($this->grader->isCorrect($question, [], ['numeric' => '12,5']));
    }

    public function testACalculeeIsGradedAgainstTheValuesThisStudentWasDrawn(): void
    {
        $question = $this->question(QuestionType::Calculee);
        $question->setNumericConfig([
            'answer' => null,
            'formula' => 'v * 2',
            'tolerance' => 0.0,
            'toleranceMode' => 'absolute',
            'unit' => null,
            'unitRequired' => false,
            'variables' => [['name' => 'v', 'min' => 10.0, 'max' => 20.0, 'step' => 1.0, 'decimals' => 0]],
        ]);

        self::assertTrue($this->grader->isCorrect($question, [], ['numeric' => '24'], ['v' => 12.0]));
        self::assertFalse($this->grader->isCorrect($question, [], ['numeric' => '24'], ['v' => 13.0]));
    }

    // --- the variables a calculée is asked with ---

    public function testTheSameStudentIsAlwaysDrawnTheSameNumbers(): void
    {
        $question = $this->question(QuestionType::Calculee);
        $question->setNumericConfig([
            'answer' => null,
            'formula' => 'v * 2',
            'tolerance' => 0.0,
            'toleranceMode' => 'absolute',
            'unit' => null,
            'unitRequired' => false,
            'variables' => [['name' => 'v', 'min' => 10.0, 'max' => 20.0, 'step' => 1.0, 'decimals' => 0]],
        ]);

        // Nothing stores a video cue's draw, unlike a quiz attempt's: reloading the page mid-answer
        // has to ask the same question, so the draw is a function of the student and the marker.
        $first = $this->grader->variablesFor($question, 7, 42);

        self::assertSame($first, $this->grader->variablesFor($question, 7, 42));
        self::assertGreaterThanOrEqual(10.0, $first['v']);
        self::assertLessThanOrEqual(20.0, $first['v']);
    }

    public function testTwoStudentsAreNotAskedTheSameNumbers(): void
    {
        $question = $this->question(QuestionType::Calculee);
        $question->setNumericConfig([
            'answer' => null,
            'formula' => 'v * 2',
            'tolerance' => 0.0,
            'toleranceMode' => 'absolute',
            'unit' => null,
            'unitRequired' => false,
            'variables' => [['name' => 'v', 'min' => 0.0, 'max' => 1000.0, 'step' => 1.0, 'decimals' => 0]],
        ]);

        $drawn = [];
        foreach (range(1, 12) as $studentId) {
            $drawn[] = $this->grader->variablesFor($question, $studentId, 42)['v'];
        }

        self::assertGreaterThan(1, \count(array_unique($drawn)), 'the whole point of a calculée is that neighbours get different numbers');
    }

    public function testAQuestionWithoutVariablesDrawsNothing(): void
    {
        self::assertSame([], $this->grader->variablesFor($this->question(QuestionType::Qcm), 7, 42));
    }

    private function question(QuestionType $type): QuizQuestion
    {
        // newInstanceWithoutConstructor: a QuizQuestion needs a QuizTemplate it has no use for
        // here, and grading only ever reads the definition (same shortcut as the zones tests).
        $question = (new \ReflectionClass(QuizQuestion::class))->newInstanceWithoutConstructor();
        $question->setType($type);
        $question->setLabel('Énoncé');

        return $question;
    }
}
