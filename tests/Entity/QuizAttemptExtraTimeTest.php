<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Cohort;
use App\Entity\Program;
use App\Entity\QuizAttempt;
use App\Entity\QuizInstance;
use App\Entity\SchoolYear;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * What an « ajout de temps » actually buys a copy.
 *
 * Two budgets bound an attempt and the accommodation has to reach both, or it reaches neither in
 * practice: the per-question stopwatch, and the whole-quiz clock. The third thing standing in the
 * way is the instance's closing date, and it is the one that would have made the accommodation
 * decorative - a class that all starts at 9h30 on a 30-minute quiz closing at 10h00 gives the
 * student with a third more exactly the same half hour as everybody else. The date therefore slides
 * by the seconds that were granted, and by nothing else.
 */
class QuizAttemptExtraTimeTest extends TestCase
{
    public function testAPerQuestionBudgetGrowsAndRoundsUp(): void
    {
        $attempt = $this->attempt(globalMinutes: null, closesAt: null, percent: '33.33');

        self::assertSame(40, $attempt->allowedSeconds(30));
        // Nothing to grow on an unlimited question.
        self::assertNull($attempt->allowedSeconds(null));
    }

    public function testACopyWithoutAnAccommodationIsUntouched(): void
    {
        $attempt = $this->attempt(globalMinutes: 30, closesAt: null, percent: null);

        self::assertSame(30, $attempt->allowedSeconds(30));
        self::assertEquals(
            $attempt->getStartedAt()->modify('+30 minutes'),
            $attempt->getTimeLimitAt(),
        );
    }

    public function testTheWholeQuizClockGrowsBySeconds(): void
    {
        $attempt = $this->attempt(globalMinutes: 30, closesAt: null, percent: '33.33');

        // 1 800 s x 1,3333 = 2 399,94 -> 2 400 s, which is 40 minutes.
        self::assertEquals(
            $attempt->getStartedAt()->modify('+2400 seconds'),
            $attempt->getTimeLimitAt(),
        );
    }

    public function testTheClosingDateSlidesByExactlyWhatWasGranted(): void
    {
        $startedAt = new \DateTimeImmutable('2026-09-12 09:30:00');
        // The class window shuts at 10h00, the budget is the same half hour: without the slide the
        // accommodation would buy nothing at all.
        $attempt = $this->attempt(globalMinutes: 30, closesAt: new \DateTimeImmutable('2026-09-12 10:00:00'), percent: '33.33', startedAt: $startedAt);

        // 600 s granted, so the window shuts at 10h10 for this copy and the budget runs out at the
        // same instant - whichever comes first, it is ten minutes later than for everybody else.
        self::assertEquals(new \DateTimeImmutable('2026-09-12 10:10:00'), $attempt->getTimeLimitAt());
    }

    public function testAQuizWithNoGlobalBudgetKeepsItsClosingDate(): void
    {
        // Nothing was measured in duration, so there is nothing to grow: the closing date is the
        // class-wide window and stays where the teacher put it. The per-question budgets still grow.
        $attempt = $this->attempt(globalMinutes: null, closesAt: new \DateTimeImmutable('2026-09-12 10:00:00'), percent: '33.33');

        self::assertEquals(new \DateTimeImmutable('2026-09-12 10:00:00'), $attempt->getTimeLimitAt());
        self::assertSame(40, $attempt->allowedSeconds(30));
    }

    public function testTheGrantedTimeIsReadableOffTheCopy(): void
    {
        $attempt = $this->attempt(globalMinutes: null, closesAt: null, percent: '33.33');

        self::assertSame('33,33 %', $attempt->extraTimePercentLabel());
        self::assertNull($this->attempt(globalMinutes: null, closesAt: null, percent: null)->extraTimePercentLabel());
    }

    private function attempt(?int $globalMinutes, ?\DateTimeImmutable $closesAt, ?string $percent, ?\DateTimeImmutable $startedAt = null): QuizAttempt
    {
        $program = new Program('SIO-2 2026-2027', 'SIO-2', $this->createStub(Cohort::class), $this->createStub(SchoolYear::class));
        $instance = new QuizInstance($program, new User('teacher'));
        $instance->setGlobalTimeMinutes($globalMinutes);
        $instance->setClosesAt($closesAt);

        $attempt = new QuizAttempt($instance, new User('student'));
        $attempt->setExtraTimePercent($percent);

        if (null !== $startedAt) {
            $attempt->restartClock($startedAt);
        }

        return $attempt;
    }
}
