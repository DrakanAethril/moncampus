<?php

declare(strict_types=1);

namespace App\Tests\Service\OnlineCourse;

use App\Service\OnlineCourse\OnlineCourseTestRun;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic of one go at a course's test - what the session keeps between two questions.
 */
class OnlineCourseTestRunTest extends TestCase
{
    public function testAnsweringEveryQuestionEndsTheRunAndScoresIt(): void
    {
        $run = OnlineCourseTestRun::draw(7, 3, [11, 12, 13], 42);

        self::assertSame(0, $run->nextIndex());
        $run->record(0, true);
        $run->record(1, false);
        self::assertFalse($run->isFinished());
        self::assertSame(2, $run->nextIndex());

        $run->record(2, true);
        self::assertTrue($run->isFinished());
        self::assertNull($run->nextIndex());
        self::assertSame(2, $run->correctCount());
        self::assertSame(67, $run->scorePercent());
    }

    public function testAQuestionKeepsItsFirstVerdict(): void
    {
        $run = OnlineCourseTestRun::draw(7, 3, [11, 12], 42);

        $run->record(0, false);
        $run->record(0, true);
        $run->record(9, true);

        self::assertSame(0, $run->correctCount());
        self::assertSame(1, $run->nextIndex());
    }

    public function testARunWithNothingToAskScoresZero(): void
    {
        $run = OnlineCourseTestRun::draw(7, 3, [], 42);
        $run->finish();

        self::assertTrue($run->isFinished());
        self::assertSame(0, $run->scorePercent());
    }

    public function testItSurvivesTheSessionAndRefusesAnythingElse(): void
    {
        $run = OnlineCourseTestRun::draw(7, 3, [11], 42);
        $run->record(0, true);

        $back = OnlineCourseTestRun::fromArray($run->toArray());
        self::assertNotNull($back);
        self::assertSame($run->toArray(), $back->toArray());

        self::assertNull(OnlineCourseTestRun::fromArray(null));
        self::assertNull(OnlineCourseTestRun::fromArray(['courseId' => '7']));
        self::assertNull(OnlineCourseTestRun::fromArray([...$run->toArray(), 'questions' => [['id' => 'x']]]));
    }
}
