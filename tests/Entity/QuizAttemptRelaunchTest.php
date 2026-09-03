<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Cohort;
use App\Entity\Program;
use App\Entity\QuizAttempt;
use App\Entity\QuizAttemptAnswer;
use App\Entity\QuizInstance;
use App\Entity\QuizInstanceQuestion;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Enum\AttemptOrigin;
use App\Enum\QuizMode;
use PHPUnit\Framework\TestCase;

/**
 * What a teacher's « Relancer » actually grants.
 *
 * The gesture is the way back out of « une seule tentative » - a mis-click, a browser that crashed,
 * a machine that died mid-exam. It only means something if the granted attempt is takeable, and two
 * instants stand between it and that: the instance's closing date, and the attempt's own global
 * budget. Both were being read the same way for a granted attempt as for an ordinary one, so the
 * gesture produced an attempt that was already past its limit the moment it existed - the teacher
 * saw « Le quiz a été relancé », the student opened it and was told it was interrupted.
 *
 * These are the two rules that make it mean what it says. Neither loosens what an évaluation is:
 * the granted attempt still runs against its own time budget, and nothing here is reachable without
 * a teacher having pressed the button.
 */
class QuizAttemptRelaunchTest extends TestCase
{
    public function testAnOrdinaryAttemptIsStillClampedByTheClosingDate(): void
    {
        $closesAt = new \DateTimeImmutable('+1 hour');
        $attempt = $this->attempt(AttemptOrigin::Initiale, $closesAt, globalMinutes: null);

        self::assertEquals($closesAt, $attempt->getTimeLimitAt());
    }

    public function testAnOrdinaryAttemptPastTheClosingDateIsPastItsLimit(): void
    {
        $attempt = $this->attempt(AttemptOrigin::Initiale, new \DateTimeImmutable('-1 hour'), globalMinutes: null);

        self::assertTrue($attempt->isPastTimeLimit());
    }

    // The rule the whole gesture rests on: a teacher notices the problem once the quiz has shut, so
    // a granted retry clamped to closesAt would be dead on arrival.
    public function testAGrantedRetryOutlivesTheClosingDate(): void
    {
        $attempt = $this->attempt(AttemptOrigin::Relance, new \DateTimeImmutable('-1 hour'), globalMinutes: null);

        self::assertNull($attempt->getTimeLimitAt());
        self::assertFalse($attempt->isPastTimeLimit());
    }

    // ... but it is still an évaluation: its own budget is the one thing that closes it.
    public function testAGrantedRetryStillRunsAgainstItsOwnBudget(): void
    {
        $attempt = $this->attempt(AttemptOrigin::Relance, new \DateTimeImmutable('-1 hour'), globalMinutes: 30);

        self::assertNotNull($attempt->getTimeLimitAt());
        self::assertFalse($attempt->isPastTimeLimit());

        $attempt->restartClock(new \DateTimeImmutable('-31 minutes'));

        self::assertTrue($attempt->isPastTimeLimit());
    }

    public function testAnAttemptNobodyHasOpenedHasBeenServedNothing(): void
    {
        $attempt = $this->attempt(AttemptOrigin::Relance, null, globalMinutes: null);
        $attempt->addAttemptAnswer(new QuizAttemptAnswer($attempt, $this->createStub(QuizInstanceQuestion::class)));

        self::assertFalse($attempt->hasBeenServed());
    }

    public function testOneQuestionOnScreenIsEnoughToCountAsOpened(): void
    {
        $attempt = $this->attempt(AttemptOrigin::Relance, null, globalMinutes: null);
        $first = new QuizAttemptAnswer($attempt, $this->createStub(QuizInstanceQuestion::class));
        $attempt->addAttemptAnswer($first);
        $attempt->addAttemptAnswer(new QuizAttemptAnswer($attempt, $this->createStub(QuizInstanceQuestion::class)));

        $first->markServed(new \DateTimeImmutable());

        self::assertTrue($attempt->hasBeenServed());
    }

    private function attempt(AttemptOrigin $origin, ?\DateTimeImmutable $closesAt, ?int $globalMinutes): QuizAttempt
    {
        $program = new Program('SIO-2 2026-2027', 'SIO-2', $this->createStub(Cohort::class), $this->createStub(SchoolYear::class));
        $instance = new QuizInstance($program, new User('teacher'));
        $instance->setMode(QuizMode::Evaluation);
        $instance->setClosesAt($closesAt);
        $instance->setGlobalTimeMinutes($globalMinutes);

        $attempt = new QuizAttempt($instance, new User('student'));
        $attempt->setOrigin($origin);

        return $attempt;
    }
}
