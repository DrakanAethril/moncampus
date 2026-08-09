<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\QuizInstanceQuestion;
use App\Entity\QuizQuestionDefinition;
use App\Enum\BlankMode;
use App\Enum\QuestionType;
use App\Service\QuizAnswerChecker;
use PHPUnit\Framework\TestCase;

/**
 * "Is this answer right?", for every question type.
 *
 * The rule existed twice - once in App\Service\QuizAttemptGrader for a real attempt, once inline in
 * App\Controller\QuizLibraryController for the "Tester" preview - with a comment on the second
 * promising it mirrored the first "exactly". Nothing enforced that promise, and a divergence would
 * mean the preview telling a teacher their question works while a student's attempt marks the same
 * answer wrong. These tests pin the single rule both now call.
 */
class QuizAnswerCheckerTest extends TestCase
{
    private QuizAnswerChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new QuizAnswerChecker();
    }

    // --- Single-choice: QCM, vrai/faux, image ---

    public function testSingleChoiceNeedsExactlyTheOneCorrectAnswer(): void
    {
        $question = $this->question(QuestionType::Qcm);
        $answers = [
            ['id' => 1, 'correct' => false, 'orderIndex' => 0],
            ['id' => 2, 'correct' => true, 'orderIndex' => 1],
            ['id' => 3, 'correct' => false, 'orderIndex' => 2],
        ];

        self::assertTrue($this->checker->isCorrect($question, $answers, [2]));
        self::assertFalse($this->checker->isCorrect($question, $answers, [1]));
    }

    public function testSingleChoiceRefusesAnEmptyOrMultipleSelection(): void
    {
        $question = $this->question(QuestionType::VraiFaux);
        $answers = [
            ['id' => 1, 'correct' => true, 'orderIndex' => 0],
            ['id' => 2, 'correct' => false, 'orderIndex' => 1],
        ];

        self::assertFalse($this->checker->isCorrect($question, $answers, []), 'no answer is not a right answer');
        self::assertFalse($this->checker->isCorrect($question, $answers, [1, 2]), 'ticking everything is not a right answer');
    }

    public function testSingleChoiceWithNoCorrectAnswerCanNeverBeRight(): void
    {
        // A question the teacher left without a correct answer must not hand out a point.
        $question = $this->question(QuestionType::Image);
        $answers = [['id' => 1, 'correct' => false, 'orderIndex' => 0]];

        self::assertFalse($this->checker->isCorrect($question, $answers, [1]));
    }

    // --- Multiple choice ---

    public function testMultiNeedsTheWholeCorrectSetAndNothingElse(): void
    {
        $question = $this->question(QuestionType::QcmMulti);
        $answers = [
            ['id' => 1, 'correct' => true, 'orderIndex' => 0],
            ['id' => 2, 'correct' => false, 'orderIndex' => 1],
            ['id' => 3, 'correct' => true, 'orderIndex' => 2],
        ];

        self::assertTrue($this->checker->isCorrect($question, $answers, [1, 3]));
        self::assertTrue($this->checker->isCorrect($question, $answers, [3, 1]), 'order does not matter here');
        self::assertFalse($this->checker->isCorrect($question, $answers, [1]), 'a partial set is wrong');
        self::assertFalse($this->checker->isCorrect($question, $answers, [1, 2, 3]), 'one extra tick is wrong');
    }

    public function testMultiWithNoCorrectAnswerCanNeverBeRight(): void
    {
        $question = $this->question(QuestionType::QcmMulti);
        $answers = [['id' => 1, 'correct' => false, 'orderIndex' => 0]];

        self::assertFalse($this->checker->isCorrect($question, $answers, []), 'even an empty answer scores nothing');
    }

    // --- Ordering ---

    public function testOrderComparesTheWholeSequenceAgainstOrderIndex(): void
    {
        // An "ordre" question stores its expected sequence as the answers' orderIndex - every
        // answer takes part, whatever its `correct` flag says.
        $question = $this->question(QuestionType::Ordre);
        $answers = [
            ['id' => 30, 'correct' => false, 'orderIndex' => 2],
            ['id' => 10, 'correct' => false, 'orderIndex' => 0],
            ['id' => 20, 'correct' => false, 'orderIndex' => 1],
        ];

        self::assertTrue($this->checker->isCorrect($question, $answers, [10, 20, 30]));
        self::assertFalse($this->checker->isCorrect($question, $answers, [10, 30, 20]));
        self::assertFalse($this->checker->isCorrect($question, $answers, [10, 20]), 'a short sequence is wrong');
    }

    // --- Texte à trous ---

    public function testBlanksAreAllOrNothingHere(): void
    {
        $question = $this->blanksQuestion('codée sur ... bits, soit ... octets', [['32'], ['4']]);

        self::assertTrue($this->checker->isCorrect($question, [], [], ['32', '4']));
        self::assertFalse($this->checker->isCorrect($question, [], [], ['32', '8']), 'one wrong blank fails the answer');
        self::assertFalse($this->checker->isCorrect($question, [], [], ['32']), 'a missing blank fails the answer');
    }

    public function testAQuestionWithNoBlankConfiguredIsNeverRight(): void
    {
        $question = $this->blanksQuestion('un énoncé sans le moindre trou', []);

        self::assertFalse($this->checker->isCorrect($question, [], [], []));
    }

    public function testABlankWithoutAnyAcceptedAnswerIsNeverRight(): void
    {
        // Otherwise an unfinished question would hand out free points.
        $question = $this->blanksQuestion('sur ... bits, soit ... octets', [['32'], []]);

        self::assertFalse($this->checker->isCorrect($question, [], [], ['32', 'anything']));
    }

    public function testBlankResultsReportEachBlankInTextOrder(): void
    {
        $question = $this->blanksQuestion('... puis ... puis ...', [['32'], ['4'], ['8']]);

        self::assertSame([true, false, true], $this->checker->blankResults($question, ['32', '5', '8']));
    }

    public function testBlankResultsAreEmptyForAnyOtherType(): void
    {
        self::assertSame([], $this->checker->blankResults($this->question(QuestionType::Qcm), ['x']));
    }

    private function question(QuestionType $type): QuizQuestionDefinition
    {
        $question = (new \ReflectionClass(QuizInstanceQuestion::class))->newInstanceWithoutConstructor();
        $question->setType($type);

        return $question;
    }

    /**
     * The statement matters as much as the config: getBlankAnswers() is bounded by the number of
     * "..." the label currently has, precisely so a stale config never shifts answers onto the
     * wrong blank. A question whose label carries no blank has no blank to grade.
     *
     * @param list<list<string>> $blankAnswers
     */
    private function blanksQuestion(string $label, array $blankAnswers): QuizQuestionDefinition
    {
        $question = (new \ReflectionClass(QuizInstanceQuestion::class))->newInstanceWithoutConstructor();
        $question->setType(QuestionType::TexteATrous);
        $question->setLabel($label);
        $question->setBlankMode(BlankMode::Libre);
        $question->setIgnoreCase(true);
        $question->setBlankAnswers($blankAnswers);

        return $question;
    }
}
