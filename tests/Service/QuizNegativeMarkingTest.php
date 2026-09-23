<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Program;
use App\Entity\QuizAttempt;
use App\Entity\QuizAttemptAnswer;
use App\Entity\QuizInstance;
use App\Entity\QuizInstanceQuestion;
use App\Entity\User;
use App\Enum\AttemptStatus;
use App\Enum\BlankMode;
use App\Enum\QuestionType;
use App\Enum\QuizPenaltyMode;
use App\Service\QuizAnswerChecker;
use App\Service\QuizAttemptConcluder;
use App\Service\QuizAttemptGrader;
use App\Service\QuizSupervisionReportBuilder;
use PHPUnit\Framework\TestCase;

/**
 * « Note négative sur erreurs » - the launch setting that makes a wrong answer cost points
 * (App\Entity\QuizInstance::penaltyFor()).
 *
 * Three rules are worth pinning, because each of them is a decision rather than arithmetic: the
 * penalty falls on the answer that earned *nothing* (a partly right texte à trous is not a guess),
 * an unanswered question is never penalised, and the floor at zero is on the copy's total alone -
 * each question keeps the negative it earned, so a correction still adds up to the badge.
 */
class QuizNegativeMarkingTest extends TestCase
{
    private QuizAttemptGrader $grader;

    protected function setUp(): void
    {
        $this->grader = new QuizAttemptGrader(new QuizAnswerChecker());
    }

    // --- what the instance says a wrong answer costs ---

    public function testAQuizLaunchedWithoutThePenaltyCostsNothing(): void
    {
        $instance = $this->instance();

        self::assertSame(0.0, $instance->penaltyFor(1.0));
        // Even with the two settings filled in: the checkbox is what decides, not the numbers left
        // behind in the fields under it.
        $instance->setPenaltyPoints(2.0);
        self::assertSame(0.0, $instance->penaltyFor(1.0));
    }

    public function testAFixedPenaltyIgnoresTheQuestionWeight(): void
    {
        $instance = $this->instance()->setNegativeMarking(true)->setPenaltyPoints(0.5);

        self::assertSame(0.5, $instance->penaltyFor(1.0));
        self::assertSame(0.5, $instance->penaltyFor(3.0));
    }

    public function testAScalePenaltyIsAShareOfTheQuestionWeight(): void
    {
        $instance = $this->instance()
            ->setNegativeMarking(true)
            ->setPenaltyMode(QuizPenaltyMode::Scale)
            ->setPenaltyPercent(25);

        self::assertSame(0.25, $instance->penaltyFor(1.0));
        self::assertSame(1.0, $instance->penaltyFor(4.0));
    }

    public function testAWrongAnswerNeverCostsMoreThanTheQuestionWasWorth(): void
    {
        $instance = $this->instance()
            ->setNegativeMarking(true)
            ->setPenaltyMode(QuizPenaltyMode::Scale)
            ->setPenaltyPercent(250);

        self::assertSame(100, $instance->getPenaltyPercent());
        self::assertSame(2.0, $instance->penaltyFor(2.0));
    }

    // --- what a graded answer is then worth ---

    public function testAWrongQcmCostsThePenaltyAndARightOneStillPaysOne(): void
    {
        $instance = $this->instance()->setNegativeMarking(true)->setPenaltyPoints(0.5);
        $question = $this->qcm($instance);

        // 1 and 2 are the ids qcm() hands its right and its wrong answer.
        self::assertSame(1.0, $this->grader->score($question, [1]));
        self::assertSame(-0.5, $this->grader->score($question, [2]));
    }

    public function testAPartlyRightAnswerIsNeverPenalised(): void
    {
        // 2 blanks of 3 on a 1-point question pays 0.67 - it is not a guess, and taking half a
        // point back off it would read as an arithmetic bug rather than as a rule.
        $instance = $this->instance()->setNegativeMarking(true)->setPenaltyPoints(0.5);
        $question = $this->blanks($instance, 'sur ... bits, en ... octets, soit ...', [['32'], ['4'], ['255.255.255.0']]);

        self::assertSame(0.67, $this->grader->score($question, [], ['32', '4', 'rien']));
        // Nothing right at all is what the penalty is for, and here it is weighed on the whole
        // question rather than on one blank.
        self::assertSame(-0.5, $this->grader->score($question, [], ['a', 'b', 'c']));
    }

    // --- what the copy is finally marked ---

    public function testTheFloorStopsACopyAtZeroUnlessTheTeacherAllowedOtherwise(): void
    {
        $instance = $this->instance()->setNegativeMarking(true)->setPenaltyPoints(1.0);
        $attempt = $this->attemptOf($instance, [1.0, -1.0, -1.0, -1.0]);

        $this->concluder()->conclude($attempt, AttemptStatus::Termine);
        self::assertSame(0.0, $attempt->getCorrectCount(), 'the sum is -2, the copy stops at 0');
        self::assertSame(4, $attempt->getQuestionTotal());

        $instance->setNegativeScoreAllowed(true);
        $again = $this->attemptOf($instance, [1.0, -1.0, -1.0, -1.0]);
        $this->concluder()->conclude($again, AttemptStatus::Termine);
        self::assertSame(-2.0, $again->getCorrectCount());
        self::assertSame(-50.0, $again->getScorePercent());
    }

    public function testAQuestionNeverReachedIsNotPenalised(): void
    {
        // An attempt cut short by the timer scores what was done and owes nothing for the rest -
        // the unanswered rows are graded nowhere, so they carry no score at all.
        $instance = $this->instance()->setNegativeMarking(true)->setPenaltyPoints(0.5);
        $attempt = $this->attemptOf($instance, [1.0, -0.5, null, null]);

        $this->concluder()->conclude($attempt, AttemptStatus::Interrompu);

        self::assertSame(0.5, $attempt->getCorrectCount());
        self::assertSame(4, $attempt->getQuestionTotal(), 'the barème still counts every drawn question');
    }

    private function concluder(): QuizAttemptConcluder
    {
        // Never asked anything: none of these quizzes is supervised, and the concluder only
        // reaches for the report when one is.
        return new QuizAttemptConcluder($this->createStub(QuizSupervisionReportBuilder::class));
    }

    private function instance(): QuizInstance
    {
        return new QuizInstance($this->stub(Program::class), $this->stub(User::class));
    }

    /** @param list<float|null> $scores null = question never answered */
    private function attemptOf(QuizInstance $instance, array $scores): QuizAttempt
    {
        $attempt = new QuizAttempt($instance, $this->stub(User::class));

        foreach ($scores as $score) {
            $answer = new QuizAttemptAnswer($attempt, $this->qcm($instance));
            if (null !== $score) {
                $answer->setAnsweredAt(new \DateTimeImmutable())->setScore($score);
            }
            $attempt->addAttemptAnswer($answer);
        }

        return $attempt;
    }

    private function qcm(QuizInstance $instance): QuizInstanceQuestion
    {
        $question = new QuizInstanceQuestion($instance);
        $question->setType(QuestionType::Qcm)->setLabel('Quelle balise ?');
        $question->addAnswer($this->answer($question, 'nav', true));
        $question->addAnswer($this->answer($question, 'div', false));

        return $question;
    }

    /** @param list<list<string>> $answers what is accepted in each blank, in text order */
    private function blanks(QuizInstance $instance, string $label, array $answers): QuizInstanceQuestion
    {
        $question = new QuizInstanceQuestion($instance);
        $question->setType(QuestionType::TexteATrous);
        $question->setLabel($label);
        $question->setBlankMode(BlankMode::Libre);
        $question->setIgnoreCase(true);
        $question->setBlankAnswers($answers);

        return $question;
    }

    private function answer(QuizInstanceQuestion $question, string $label, bool $correct): \App\Entity\QuizInstanceAnswer
    {
        $answer = new \App\Entity\QuizInstanceAnswer($question);
        $answer->setLabel($label)->setIsCorrect($correct);
        // Never persisted here, so nothing hands these rows an id - the grader compares the ids it
        // is given against the ones it reads, and null would match every answer at once.
        (new \ReflectionProperty($answer, 'id'))->setValue($answer, $correct ? 1 : 2);

        return $answer;
    }

    /** @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function stub(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
