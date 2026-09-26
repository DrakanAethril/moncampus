<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\QuizPoolShares;
use PHPUnit\Framework\TestCase;

final class QuizPoolSharesTest extends TestCase
{
    private QuizPoolShares $shares;

    protected function setUp(): void
    {
        $this->shares = new QuizPoolShares();
    }

    public function testNoShareReservesNothing(): void
    {
        self::assertSame([null, null], $this->shares->quotas([null, null], 20));
    }

    public function testASingleQuizIgnoresItsShare(): void
    {
        // A share is a split between quizzes: with one quiz there is nothing to split.
        self::assertSame([null], $this->shares->quotas([40], 20));
        self::assertNull($this->shares->violation([40], [30], 20));
    }

    public function testSharesThatCoverEverythingAddUpToTheDraw(): void
    {
        // 33/33/34 of 10 is 3.3/3.3/3.4: the largest remainders take the missing question, so the
        // quotas always sum to the draw itself.
        $quotas = $this->shares->quotas([33, 33, 34], 10);

        self::assertSame(10, array_sum(array_map('intval', $quotas)));
        self::assertSame([3, 3, 4], $quotas);
    }

    public function testTiesOnTheRemainderGoToTheFirstQuiz(): void
    {
        self::assertSame([2, 1], $this->shares->quotas([50, 50], 3));
    }

    public function testAPartialShareLeavesTheRestToTheOthers(): void
    {
        self::assertSame([6, null, null], $this->shares->quotas([30, null, null], 20));
    }

    public function testAPartialShareIsRoundedToTheNearestQuestion(): void
    {
        // 35 % of 10 is 3.5, rounded to 4; 34 % is 3.4, rounded to 3.
        self::assertSame([4, null], $this->shares->quotas([35, null], 10));
        self::assertSame([3, null], $this->shares->quotas([34, null], 10));
    }

    public function testSharesAboveAHundredAreRefused(): void
    {
        self::assertSame(
            ['key' => 'quizLaunchShareOverflowError', 'params' => ['%sum%' => 120]],
            $this->shares->violation([70, 50], [30, 30], 20),
        );
    }

    public function testEverySharedQuizMustAddUpToAHundred(): void
    {
        // Nobody is left to take the missing 10 % - the teacher named every quiz and meant a split.
        self::assertSame(
            ['key' => 'quizLaunchShareIncompleteError', 'params' => ['%sum%' => 90]],
            $this->shares->violation([60, 30], [30, 30], 20),
        );
    }

    public function testAPartialSumBelowAHundredIsFine(): void
    {
        self::assertNull($this->shares->violation([60, null], [30, 30], 20));
    }

    public function testAShareLargerThanItsQuizIsRefused(): void
    {
        // 50 % of 20 is 10 questions, and the second quiz only holds 8.
        self::assertSame(
            ['key' => 'quizLaunchShareTooLargeError', 'index' => 1, 'params' => ['%quota%' => 10, '%available%' => 8, '%share%' => 50]],
            $this->shares->violation([50, 50], [30, 8], 20),
        );
    }

    public function testTheQuizzesWithoutAShareMustHoldTheRest(): void
    {
        // 20 % of 15 is 3; the 12 left must come from the second quiz, which holds 5.
        self::assertSame(
            ['key' => 'quizLaunchShareRestTooLargeError', 'params' => ['%rest%' => 12, '%available%' => 5]],
            $this->shares->violation([20, null], [10, 5], 15),
        );
    }

    public function testNoShareIsNeverAViolation(): void
    {
        self::assertNull($this->shares->violation([null, null], [2, 2], 20));
    }
}
