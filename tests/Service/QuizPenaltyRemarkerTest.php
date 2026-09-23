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
use App\Enum\QuestionType;
use App\Enum\QuizPenaltyMode;
use App\Repository\QuizAttemptRepository;
use App\Service\QuizAttemptConcluder;
use App\Service\QuizPenaltyRemarker;
use App\Service\QuizSupervisionReportBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Moving « note négative sur erreurs » on a quiz that has already been sat - what « Modifier le
 * quiz » runs on every save (App\Service\QuizPenaltyRemarker).
 *
 * The whole feature rests on one invariant, which is what these tests really pin: the penalty is
 * only ever charged to an answer that earned nothing, so what an answer was worth stays recoverable
 * from the single stored score and no student's selections are ever re-read.
 */
class QuizPenaltyRemarkerTest extends TestCase
{
    public function testRaisingThePenaltyRemarksTheCopiesAlreadyHandedIn(): void
    {
        // 1 right, 3 wrong, half a point each: 1 - 1.5 = -0.5, floored to 0.
        $instance = $this->instance(0.5);
        $attempt = $this->concludedAttempt($instance, [1.0, -0.5, -0.5, -0.5]);

        self::assertSame(0.0, $attempt->getCorrectCount());

        $instance->setPenaltyPoints(0.25);
        self::assertSame(1, $this->remarker($attempt)->reapply($instance));

        // The same right answer still pays 1, the three wrong ones now cost a quarter each.
        self::assertSame(0.25, $attempt->getCorrectCount());
        self::assertSame([1.0, -0.25, -0.25, -0.25], $this->scoresOf($attempt));
    }

    public function testTurningThePenaltyOffGivesTheLostPointsBack(): void
    {
        $instance = $this->instance(0.5);
        $attempt = $this->concludedAttempt($instance, [1.0, -0.5, -0.5, -0.5]);

        $instance->setNegativeMarking(false);
        self::assertSame(1, $this->remarker($attempt)->reapply($instance));

        self::assertSame([1.0, 0.0, 0.0, 0.0], $this->scoresOf($attempt));
        self::assertSame(1.0, $attempt->getCorrectCount());
    }

    public function testTurningThePenaltyOnChargesTheAnswersThatHadEarnedNothing(): void
    {
        // The copy was sat with no penalty at all, so its wrong answers are stored as a plain 0 -
        // which is exactly what the charge is read off.
        $instance = $this->instance(0.5)->setNegativeMarking(false);
        $attempt = $this->concludedAttempt($instance, [1.0, 0.0, 0.0]);

        self::assertSame(1.0, $attempt->getCorrectCount());

        $instance->setNegativeMarking(true)->setNegativeScoreAllowed(true);
        self::assertSame(1, $this->remarker($attempt)->reapply($instance));

        self::assertSame([1.0, -0.5, -0.5], $this->scoresOf($attempt));
        self::assertSame(0.0, $attempt->getCorrectCount());
    }

    public function testChangingOnlyThePercentageRemarksTheCopies(): void
    {
        $instance = $this->instance(0.5)->setPenaltyMode(QuizPenaltyMode::Scale)->setPenaltyPercent(25);
        $attempt = $this->concludedAttempt($instance, [-1.0], [4.0]);

        $instance->setPenaltyPercent(75);
        self::assertSame(1, $this->remarker($attempt)->reapply($instance));

        self::assertSame([-3.0], $this->scoresOf($attempt));
    }

    public function testSwitchingBackFromAScalePenaltyToAFixedOne(): void
    {
        $instance = $this->instance(0.5)->setPenaltyMode(QuizPenaltyMode::Scale)->setPenaltyPercent(50)->setNegativeScoreAllowed(true);
        $attempt = $this->concludedAttempt($instance, [-0.5, -2.0], [1.0, 4.0]);

        $instance->setPenaltyMode(QuizPenaltyMode::Fixed)->setPenaltyPoints(1.0);
        $this->remarker($attempt)->reapply($instance);

        // Back to one flat point whatever the question was worth - the 4-point one included.
        self::assertSame([-1.0, -1.0], $this->scoresOf($attempt));
        self::assertSame(-2.0, $attempt->getCorrectCount());
    }

    public function testLiftingTheFloorAloneUncoversTheTotalTheCopyHadAllAlong(): void
    {
        // Nothing about the answers moves here: the floor is on the total, so this is the one
        // change that re-marks a copy without touching a single line of it.
        $instance = $this->instance(1.0);
        $attempt = $this->concludedAttempt($instance, [1.0, -1.0, -1.0, -1.0]);

        self::assertSame(0.0, $attempt->getCorrectCount());

        $instance->setNegativeScoreAllowed(true);
        self::assertSame(1, $this->remarker($attempt)->reapply($instance));

        self::assertSame(-2.0, $attempt->getCorrectCount());
        self::assertSame([1.0, -1.0, -1.0, -1.0], $this->scoresOf($attempt));
    }

    public function testSwitchingToAScalePenaltyWeighsEachQuestionOnItsOwnBareme(): void
    {
        // The wrong answers sit on questions worth 1 and 4 points; at 50 % they now cost 0.5 and 2.
        $instance = $this->instance(0.5);
        $attempt = $this->concludedAttempt($instance, [-0.5, -0.5], [1.0, 4.0]);

        $instance->setPenaltyMode(QuizPenaltyMode::Scale)->setPenaltyPercent(50)->setNegativeScoreAllowed(true);
        $this->remarker($attempt)->reapply($instance);

        self::assertSame([-0.5, -2.0], $this->scoresOf($attempt));
        self::assertSame(-2.5, $attempt->getCorrectCount());
    }

    public function testAPartlyRightAnswerKeepsWhatItEarnedThroughEveryChange(): void
    {
        // 0.67 was earned, never penalised, and must survive the penalty being raised, lowered and
        // switched to a percentage - this is the invariant the whole re-marking leans on.
        $instance = $this->instance(0.5);
        $attempt = $this->concludedAttempt($instance, [0.67, -0.5]);

        $instance->setPenaltyPoints(2.0);
        $this->remarker($attempt)->reapply($instance);
        self::assertSame([0.67, -2.0], $this->scoresOf($attempt));

        $instance->setPenaltyMode(QuizPenaltyMode::Scale)->setPenaltyPercent(100);
        $this->remarker($attempt)->reapply($instance);
        self::assertSame([0.67, -1.0], $this->scoresOf($attempt));
    }

    public function testReapplyingTheSameSettingsChangesNothingAndReportsNothing(): void
    {
        $instance = $this->instance(0.5);
        $attempt = $this->concludedAttempt($instance, [1.0, -0.5]);

        self::assertSame(0, $this->remarker($attempt)->reapply($instance), 'a rename must not announce a re-marking');
        self::assertSame([1.0, -0.5], $this->scoresOf($attempt));
    }

    public function testAnAnswerCarryingNoScoreIsLeftAlone(): void
    {
        // Either the question was never reached, or the row predates the score column - inventing a
        // zero for it would start charging a penalty for a question nobody saw.
        $instance = $this->instance(0.5);
        $attempt = $this->concludedAttempt($instance, [1.0, null, null]);

        $instance->setPenaltyPoints(3.0);
        $this->remarker($attempt)->reapply($instance);

        self::assertSame([1.0, null, null], $this->scoresOf($attempt));
        self::assertSame(1.0, $attempt->getCorrectCount());
    }

    public function testACopyStillBeingComposedIsPutBackUnderOneRuleWithoutBeingMarked(): void
    {
        $instance = $this->instance(0.5);
        $attempt = $this->attempt($instance, [1.0, -0.5]);

        $instance->setPenaltyPoints(0.25);
        self::assertSame(0, $this->remarker($attempt)->reapply($instance), 'an unfinished copy has no mark to move');

        self::assertSame([1.0, -0.25], $this->scoresOf($attempt), 'its answers still follow the new rule');
        self::assertNull($attempt->getCorrectCount(), 'and it is marked when it is handed in, as always');
    }

    public function testRemarkingNeverRestampsHowTheCopyWasSat(): void
    {
        $instance = $this->instance(0.5);
        $attempt = $this->concludedAttempt($instance, [-0.5]);
        $attempt->setFlaggedCount(3);
        $submittedAt = $attempt->getSubmittedAt();

        $instance->setPenaltyPoints(0.25);
        $this->remarker($attempt)->reapply($instance);

        self::assertSame(AttemptStatus::Termine, $attempt->getStatus());
        self::assertSame($submittedAt, $attempt->getSubmittedAt());
        self::assertSame(3, $attempt->getFlaggedCount(), 'the surveillance count is a fact about the sitting');
    }

    // --- fixtures ---

    private function remarker(QuizAttempt $attempt): QuizPenaltyRemarker
    {
        $repository = $this->createStub(QuizAttemptRepository::class);
        $repository->method('findAllForInstanceWithAnswers')->willReturn([$attempt]);

        return new QuizPenaltyRemarker($repository, new QuizAttemptConcluder($this->createStub(QuizSupervisionReportBuilder::class)));
    }

    private function instance(float $penaltyPoints): QuizInstance
    {
        return (new QuizInstance($this->stub(Program::class), $this->stub(User::class)))
            ->setNegativeMarking(true)
            ->setPenaltyPoints($penaltyPoints);
    }

    /**
     * @param list<float|null> $scores each answer's frozen score; null = never answered
     * @param list<float>      $points each question's barème, 1 point by default
     */
    private function attempt(QuizInstance $instance, array $scores, array $points = []): QuizAttempt
    {
        $attempt = new QuizAttempt($instance, $this->stub(User::class));

        foreach ($scores as $index => $score) {
            $question = new QuizInstanceQuestion($instance);
            // Zone rather than Qcm: the answer-row types are worth exactly 1 whatever their points
            // field says, so a weighted question has to be a config-driven one to mean anything.
            $question->setType(QuestionType::Zone)->setLabel('[[z1|<nav>]]')->setPoints($points[$index] ?? 1.0);

            $answer = new QuizAttemptAnswer($attempt, $question);
            if (null !== $score) {
                $answer->setAnsweredAt(new \DateTimeImmutable())->setScore($score);
            }
            $attempt->addAttemptAnswer($answer);
        }

        return $attempt;
    }

    /**
     * @param list<float|null> $scores
     * @param list<float>      $points
     */
    private function concludedAttempt(QuizInstance $instance, array $scores, array $points = []): QuizAttempt
    {
        $attempt = $this->attempt($instance, $scores, $points);
        (new QuizAttemptConcluder($this->createStub(QuizSupervisionReportBuilder::class)))->conclude($attempt, AttemptStatus::Termine);

        return $attempt;
    }

    /** @return list<float|null> */
    private function scoresOf(QuizAttempt $attempt): array
    {
        return array_values(array_map(
            static fn (QuizAttemptAnswer $answer): ?float => $answer->getScore(),
            $attempt->getAttemptAnswers()->toArray(),
        ));
    }

    /**
     * @template T of object
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
