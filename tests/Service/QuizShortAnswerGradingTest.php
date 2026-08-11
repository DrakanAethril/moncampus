<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\QuizInstanceQuestion;
use App\Enum\BlankMode;
use App\Enum\QuestionType;
use App\Service\QuizAnswerChecker;
use App\Service\QuizAttemptGrader;
use PHPUnit\Framework\TestCase;

/**
 * Grading rules of the `reponse_courte` type (2026-08-11): the student types a word or a short
 * phrase, matched against the accepted variants.
 *
 * The point of these tests is that there is *no new comparison* to test - a short answer is stored
 * as a texte à trous with exactly one blank, so it inherits App\Util\BlankTextParser::matches()
 * whole. What is worth pinning is that the reuse actually holds: one blank whatever the statement
 * says, the two options behaving identically, and the type never picking up the bank a texte à
 * trous can have.
 */
class QuizShortAnswerGradingTest extends TestCase
{
    private QuizAnswerChecker $checker;
    private QuizAttemptGrader $grader;

    protected function setUp(): void
    {
        $this->checker = new QuizAnswerChecker();
        $this->grader = new QuizAttemptGrader($this->checker);
    }

    public function testAnyAcceptedVariantIsRight(): void
    {
        $question = $this->shortAnswer(['photosynthèse', 'la photosynthèse']);

        self::assertTrue($this->isCorrect($question, 'photosynthèse'));
        self::assertTrue($this->isCorrect($question, 'la photosynthèse'));
        self::assertFalse($this->isCorrect($question, 'respiration'));
    }

    public function testCaseAndAccentsAreForgivenByDefault(): void
    {
        $question = $this->shortAnswer(['photosynthèse']);

        self::assertTrue($this->isCorrect($question, 'Photosynthèse'));
        self::assertTrue($this->isCorrect($question, 'PHOTOSYNTHESE'));
        self::assertTrue($this->isCorrect($question, 'photosynthese'));
    }

    public function testCaseCanBeMadeToCount(): void
    {
        $question = $this->shortAnswer(['ATP'], ignoreCase: false);

        self::assertTrue($this->isCorrect($question, 'ATP'));
        self::assertFalse($this->isCorrect($question, 'atp'));
    }

    public function testATypoIsOnlyForgivenWhenAsked(): void
    {
        $strict = $this->shortAnswer(['mitochondrie']);
        self::assertFalse($this->isCorrect($strict, 'mitochondri'));

        $lenient = $this->shortAnswer(['mitochondrie'], tolerateTypo: true);
        self::assertTrue($lenient->isTolerateTypo());
        self::assertTrue($this->isCorrect($lenient, 'mitochondri'), 'one missing letter');
        self::assertFalse($this->isCorrect($lenient, 'mitocondri'), 'two is not a typo any more');
    }

    public function testSurroundingSpacesNeverCostAnAnswer(): void
    {
        $question = $this->shortAnswer(['deadline']);

        self::assertTrue($this->isCorrect($question, '  deadline '));
        self::assertTrue($this->isCorrect($question, "deadline\n"));
    }

    public function testAnEmptyAnswerIsWrong(): void
    {
        $question = $this->shortAnswer(['deadline']);

        self::assertFalse($this->isCorrect($question, ''));
        self::assertFalse($this->isCorrect($question, '   '));
    }

    public function testAQuestionWithNoAcceptedVariantIsNeverRight(): void
    {
        // Same guard as an answerless QCM: an unfinished question must not hand out points, and
        // must not accept an empty answer as "matching" its empty list either.
        $question = $this->shortAnswer([]);

        self::assertFalse($this->isCorrect($question, 'quoi que ce soit'));
        self::assertFalse($this->isCorrect($question, ''));
    }

    // --- what makes the reuse work ---

    public function testItIsAlwaysExactlyOneBlankWhateverTheStatementSays(): void
    {
        // The statement is a question, not a sentence with a hole - counting "..." in it would find
        // zero and leave the question with no answer to grade against.
        $question = $this->shortAnswer(['Paris']);
        $question->setLabel('Quelle est la capitale de la France ?');
        self::assertSame(1, $question->getBlankCount());

        // Even a statement that happens to contain an ellipsis stays one blank.
        $question->setLabel('Et alors… quelle est la capitale ?');
        self::assertSame(1, $question->getBlankCount());
        self::assertCount(1, $question->getBlankAnswers());
    }

    public function testItNeverOffersAWordBank(): void
    {
        // A stale "banque" left behind by a question switched over from a texte à trous must not
        // resurrect a bank that would spell out the answer.
        $question = $this->shortAnswer(['Paris']);
        $question->setBlanksConfig([...$question->getBlanksConfig() ?? [], 'mode' => 'banque', 'distractors' => ['Lyon']]);

        self::assertSame(BlankMode::Libre, $question->getBlankMode());
        self::assertSame([], $question->getDistractors(), 'a distractor only exists in banque mode');
    }

    public function testTheVerdictIsPerBlankJustLikeATexteATrous(): void
    {
        $question = $this->shortAnswer(['Paris']);

        self::assertSame([true], $this->checker->blankResults($question, ['Paris']));
        self::assertSame([false], $this->checker->blankResults($question, ['Lyon']));
    }

    public function testScoreIsAllOrNothingAtTheQuestionBareme(): void
    {
        // One blank, so the blanks' equal split hands over the whole barème or nothing.
        $question = $this->shortAnswer(['Paris']);
        $question->setPoints(2.5);

        self::assertSame(2.5, $this->grader->score($question, [], ['Paris']));
        self::assertSame(0.0, $this->grader->score($question, [], ['Lyon']));
    }

    // --- enum plumbing ---

    public function testItIsGroupedWithTheOtherTypedAnswerType(): void
    {
        self::assertTrue(QuestionType::ReponseCourte->usesBlankAnswers());
        self::assertTrue(QuestionType::TexteATrous->usesBlankAnswers());
        self::assertFalse(QuestionType::Qcm->usesBlankAnswers());

        self::assertFalse(QuestionType::ReponseCourte->isAvailableInLiveContest());
        self::assertFalse(QuestionType::ReponseCourte->usesAnswerRows());
    }

    private function isCorrect(QuizInstanceQuestion $question, string $typed): bool
    {
        return $this->checker->isCorrect($question, [], [], [$typed]);
    }

    /** @param list<string> $variants */
    private function shortAnswer(array $variants, bool $ignoreCase = true, bool $tolerateTypo = false): QuizInstanceQuestion
    {
        $question = (new \ReflectionClass(QuizInstanceQuestion::class))->newInstanceWithoutConstructor();
        $question->setType(QuestionType::ReponseCourte);
        $question->setLabel('Comment appelle-t-on… ?');
        $question->setBlanksConfig([
            'mode' => BlankMode::Libre->value,
            'blanks' => [['answers' => $variants]],
            'ignoreCase' => $ignoreCase,
            'tolerateTypo' => $tolerateTypo,
        ]);

        return $question;
    }
}
