<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\QuizInstanceQuestion;
use App\Enum\BlankMode;
use App\Enum\QuestionType;
use App\Service\QuizAnswerChecker;
use App\Service\QuizAttemptGrader;
use App\Util\BlankTextParser;
use PHPUnit\Framework\TestCase;

/**
 * The grading rules of the "texte à trous" question type (design/design_handoff_quiz, screens
 * 2a-2d). Worth pinning down because none of them are visible from the screens they drive: how many
 * blanks a statement has, which spellings count as the same answer, and what a half-right answer
 * scores are all decided here and only shown as a colour or a number afterwards.
 */
class QuizBlankGradingTest extends TestCase
{
    public function testThreeDotsAndTheEllipsisCharacterBothMarkABlank(): void
    {
        // Editors (macOS in particular) silently substitute "…" for a typed "..." - both spellings
        // have to mean the same thing or a teacher's question loses its blanks on save.
        self::assertSame(3, BlankTextParser::countBlanks('codée sur ... bits, en ... octets, soit ...'));
        self::assertSame(3, BlankTextParser::countBlanks('codée sur … bits, en … octets, soit …'));
        // A longer run of dots is one blank, not two.
        self::assertSame(1, BlankTextParser::countBlanks('codée sur ..... bits'));
        self::assertSame(0, BlankTextParser::countBlanks('deux points .. ne suffisent pas'));
    }

    public function testSegmentsKeepTheStatementSpacingAndNumberBlanksInTextOrder(): void
    {
        $segments = BlankTextParser::segments('sur ... bits, en ... octets');

        self::assertSame(
            [
                ['type' => 'text', 'value' => 'sur ', 'index' => -1],
                ['type' => 'blank', 'value' => '', 'index' => 0],
                ['type' => 'text', 'value' => ' bits, en ', 'index' => -1],
                ['type' => 'blank', 'value' => '', 'index' => 1],
                ['type' => 'text', 'value' => ' octets', 'index' => -1],
            ],
            $segments,
        );
    }

    public function testSurroundingWhitespaceNeverDecidesAnAnswer(): void
    {
        self::assertTrue(BlankTextParser::matches('  32 ', ['32'], false, false));
        self::assertTrue(BlankTextParser::matches('255.255.255.0', ['255.255.255.0'], false, false));
        self::assertFalse(BlankTextParser::matches('', ['32'], true, true), 'an empty blank is never right');
    }

    public function testIgnoreCaseAlsoIgnoresAccents(): void
    {
        self::assertTrue(BlankTextParser::matches('ÉLÈVE', ['élève'], true, false));
        self::assertTrue(BlankTextParser::matches('eleve', ['élève'], true, false));
        self::assertFalse(BlankTextParser::matches('ELEVE', ['élève'], false, false));
    }

    public function testTolerateTypoAcceptsExactlyOneCharacterOfDifference(): void
    {
        self::assertTrue(BlankTextParser::matches('routeur', ['routeurs'], false, true), 'one missing character');
        self::assertTrue(BlankTextParser::matches('routeus', ['routeur'], false, true), 'one substitution');
        self::assertFalse(BlankTextParser::matches('routr', ['routeur'], false, true), 'two characters off');
        // Plain Levenshtein, as the handoff specifies - a transposition costs two edits, so
        // "routuer" is *not* forgiven even though it reads like a single slip.
        self::assertFalse(BlankTextParser::matches('routuer', ['routeur'], false, true));
        self::assertFalse(BlankTextParser::matches('routeus', ['routeur'], false, false), 'tolerance is opt-in');
    }

    public function testTypoDistanceIsCountedInCharactersNotBytes(): void
    {
        // PHP's native levenshtein() would score this 2 (an "é" is two bytes), quietly making the
        // tolerance stricter on accented answers than on plain ones.
        self::assertTrue(BlankTextParser::matches('réseau', ['réseaux'], false, true));
    }

    public function testEveryBlankRightScoresTheWholeQuestion(): void
    {
        $question = $this->blanksQuestion('sur ... bits, en ... octets, soit ...', [['32'], ['4'], ['255.255.255.0']]);
        $grader = new QuizAttemptGrader(new QuizAnswerChecker());

        self::assertTrue($grader->isCorrect($question, [], ['32', '4', '255.255.255.0']));
        self::assertSame(1.0, $grader->score($question, [], ['32', '4', '255.255.255.0']));
    }

    public function testPointsAreSplitEquallyBetweenTheBlanks(): void
    {
        $question = $this->blanksQuestion('sur ... bits, en ... octets, soit ...', [['32'], ['4'], ['255.255.255.0']]);
        $grader = new QuizAttemptGrader(new QuizAnswerChecker());

        // 2 of 3 blanks on a 1-point question - the fraction the handoff's barème calls for.
        self::assertSame(0.67, $grader->score($question, [], ['32', '4', 'faux']));
        // ...but a partially right answer is still not a correct one: the ✓/✕ badge stays ✕.
        self::assertFalse($grader->isCorrect($question, [], ['32', '4', 'faux']));

        $question->setPoints(3.0);
        self::assertSame(2.0, $grader->score($question, [], ['32', '4', 'faux']));
    }

    public function testABlankLeftWithoutAnAcceptedAnswerCannotBeGotRight(): void
    {
        // An unfinished question must not hand out free points for a blank nobody can answer.
        $question = $this->blanksQuestion('sur ... bits, en ... octets', [['32'], []]);
        $grader = new QuizAttemptGrader(new QuizAnswerChecker());

        self::assertSame(0.5, $grader->score($question, [], ['32', 'quoi que ce soit']));
        self::assertFalse($grader->isCorrect($question, [], ['32', 'quoi que ce soit']));
    }

    public function testAnswersFollowTheBlanksWhenTheTeacherAddsOneToTheStatement(): void
    {
        $question = $this->blanksQuestion('sur ... bits', [['32'], ['4']]);

        // Only one blank in the text: the stale second entry must not survive as a phantom blank.
        self::assertSame([['32']], $question->getBlankAnswers());

        // Adding a blank leaves the answers already written attached to their own blank.
        $question->setLabel('sur ... bits, en ... octets');
        self::assertSame([['32'], ['4']], $question->getBlankAnswers());
    }

    public function testTheWordBankHoldsOneWordPerBlankPlusTheDistractors(): void
    {
        $question = $this->blanksQuestion('sur ... bits, en ... octets', [['32', 'trente-deux'], ['4']]);
        $question->setBlankMode(BlankMode::Banque);
        $question->setDistractors(['64', '8']);

        // One word per blank - the first variant, i.e. what the teacher typed on screen 2a - never
        // every accepted spelling, which would give the answer away.
        self::assertSame(['32', '4', '64', '8'], $question->getWordBank());
    }

    public function testDistractorsAreDroppedInFreeInputMode(): void
    {
        $question = $this->blanksQuestion('sur ... bits', [['32']]);
        $question->setDistractors(['64']);
        $question->setBlankMode(BlankMode::Libre);

        // There is no bank to mix them into - offering them anywhere would be a hint, not a decoy.
        self::assertSame([], $question->getDistractors());
    }

    public function testFillInTheBlanksIsTheOnlyTypeKeptOutOfALiveContest(): void
    {
        self::assertFalse(QuestionType::TexteATrous->isAvailableInLiveContest());
        foreach ([QuestionType::Qcm, QuestionType::QcmMulti, QuestionType::VraiFaux, QuestionType::Image, QuestionType::Ordre] as $type) {
            self::assertTrue($type->isAvailableInLiveContest());
        }
    }

    /**
     * Built without its constructor: the only thing under test here is the blanks config, and
     * reaching a QuizInstanceQuestion the normal way would mean building a whole Program + User +
     * QuizInstance graph that nothing below ever reads.
     *
     * @param list<list<string>> $answers
     */
    private function blanksQuestion(string $label, array $answers): QuizInstanceQuestion
    {
        $question = (new \ReflectionClass(QuizInstanceQuestion::class))->newInstanceWithoutConstructor();
        $question->setType(QuestionType::TexteATrous);
        $question->setLabel($label);
        $question->setBlankMode(BlankMode::Libre);
        $question->setIgnoreCase(true);
        $question->setBlankAnswers($answers);

        return $question;
    }
}
