<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\QuizInstanceState;
use PHPUnit\Framework\TestCase;

/**
 * The three states the Quiz par classes screen filters on. Everything here is deliberately
 * expressed on two nullable dates rather than on a QuizInstance: the rule is a comparison, and a
 * comparison is worth testing without a database behind it.
 */
class QuizInstanceStateTest extends TestCase
{
    private const string NOW = '2026-03-15 10:00:00';

    public function testAQuizWithNoWindowAtAllIsOngoing(): void
    {
        self::assertSame(QuizInstanceState::Ongoing, $this->stateOf(null, null));
    }

    public function testAQuizOpeningLaterIsScheduled(): void
    {
        self::assertSame(QuizInstanceState::Scheduled, $this->stateOf('2026-03-15 10:00:01', null));
        self::assertSame(QuizInstanceState::Scheduled, $this->stateOf('2026-04-01 08:00:00', '2026-04-30 08:00:00'));
    }

    public function testAQuizAlreadyClosedIsFinished(): void
    {
        self::assertSame(QuizInstanceState::Finished, $this->stateOf(null, '2026-03-15 09:59:59'));
        self::assertSame(QuizInstanceState::Finished, $this->stateOf('2026-01-01 08:00:00', '2026-02-01 08:00:00'));
    }

    public function testAQuizInsideItsWindowIsOngoing(): void
    {
        self::assertSame(QuizInstanceState::Ongoing, $this->stateOf('2026-03-01 08:00:00', '2026-03-31 08:00:00'));
        self::assertSame(QuizInstanceState::Ongoing, $this->stateOf('2026-03-01 08:00:00', null));
        self::assertSame(QuizInstanceState::Ongoing, $this->stateOf(null, '2026-03-31 08:00:00'));
    }

    /**
     * Both bounds are inclusive: a quiz is open on the very second it opens and still open on the
     * very second it closes, which is what a student hitting "Commencer" at the deadline expects.
     */
    public function testTheBoundsThemselvesCountAsOpen(): void
    {
        self::assertSame(QuizInstanceState::Ongoing, $this->stateOf(self::NOW, null));
        self::assertSame(QuizInstanceState::Ongoing, $this->stateOf(null, self::NOW));
    }

    /**
     * A window that closes before it opens is a data error, not a fourth state - being closed wins,
     * so the quiz stops appearing in the "en cours" list the screen opens on.
     */
    public function testAnInvertedWindowReadsAsFinished(): void
    {
        self::assertSame(QuizInstanceState::Finished, $this->stateOf('2026-04-01 08:00:00', '2026-02-01 08:00:00'));
    }

    private function stateOf(?string $opensAt, ?string $closesAt): QuizInstanceState
    {
        return QuizInstanceState::of(
            null !== $opensAt ? new \DateTimeImmutable($opensAt) : null,
            null !== $closesAt ? new \DateTimeImmutable($closesAt) : null,
            new \DateTimeImmutable(self::NOW),
        );
    }
}
