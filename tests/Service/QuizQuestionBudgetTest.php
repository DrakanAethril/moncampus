<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\QuizQuestionBudget;
use PHPUnit\Framework\TestCase;

/**
 * The per-question budget, which used to live only in the browser and which a reload reset.
 *
 * Everything here is expressed on two instants and an integer, deliberately: the rule is a
 * subtraction, and the test that pins it should not have to build a Program to say so.
 */
class QuizQuestionBudgetTest extends TestCase
{
    private const string SERVED = '2026-08-26 10:00:00';

    public function testAnUnlimitedQuestionHasNoDeadline(): void
    {
        self::assertNull(QuizQuestionBudget::deadline($this->at(self::SERVED), null));
        self::assertFalse(QuizQuestionBudget::isLate($this->at(self::SERVED), null, $this->at('2026-08-26 18:00:00')));
    }

    public function testAQuestionNeverServedIsNeverLate(): void
    {
        self::assertNull(QuizQuestionBudget::deadline(null, 30));
        self::assertFalse(QuizQuestionBudget::isLate(null, 30, $this->at('2026-08-26 18:00:00')));
    }

    public function testTheDeadlineIsTheBudgetPlusItsGrace(): void
    {
        $deadline = QuizQuestionBudget::deadline($this->at(self::SERVED), 30);

        self::assertNotNull($deadline);
        self::assertSame('2026-08-26 10:00:35', $deadline->format('Y-m-d H:i:s'));
    }

    public function testAnAnswerWithinTheBudgetIsKept(): void
    {
        self::assertFalse(QuizQuestionBudget::isLate($this->at(self::SERVED), 30, $this->at('2026-08-26 10:00:29')));
    }

    /**
     * The countdown submits the form the instant it reaches zero, so the POST always lands a little
     * after the deadline. Refusing it would mean refusing the answers the application's own client
     * sent on time.
     */
    public function testAnAnswerInsideTheGraceIsStillKept(): void
    {
        self::assertFalse(QuizQuestionBudget::isLate($this->at(self::SERVED), 30, $this->at('2026-08-26 10:00:34')));
    }

    public function testAnAnswerPastTheGraceIsRefused(): void
    {
        self::assertTrue(QuizQuestionBudget::isLate($this->at(self::SERVED), 30, $this->at('2026-08-26 10:00:36')));
    }

    /** A budget of zero or less is a half-filled form, not a question nobody may answer. */
    public function testANonPositiveBudgetBoundsNothing(): void
    {
        self::assertNull(QuizQuestionBudget::deadline($this->at(self::SERVED), 0));
        self::assertFalse(QuizQuestionBudget::isLate($this->at(self::SERVED), -5, $this->at('2026-08-26 12:00:00')));
    }

    /**
     * The point of the whole lot: what is left is counted from the FIRST display. Coming back to
     * the question twenty seconds later leaves ten, not thirty.
     */
    public function testRemainingSecondsCountFromTheFirstDisplay(): void
    {
        self::assertSame(10, QuizQuestionBudget::remainingSeconds($this->at(self::SERVED), 30, $this->at('2026-08-26 10:00:20')));
    }

    public function testRemainingSecondsNeverGoNegative(): void
    {
        self::assertSame(0, QuizQuestionBudget::remainingSeconds($this->at(self::SERVED), 30, $this->at('2026-08-26 10:05:00')));
    }

    public function testRemainingSecondsAreNullWhenNothingBoundsTheQuestion(): void
    {
        self::assertNull(QuizQuestionBudget::remainingSeconds($this->at(self::SERVED), null, $this->at('2026-08-26 10:00:20')));
        self::assertNull(QuizQuestionBudget::remainingSeconds(null, 30, $this->at('2026-08-26 10:00:20')));
    }

    private function at(string $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment);
    }
}
