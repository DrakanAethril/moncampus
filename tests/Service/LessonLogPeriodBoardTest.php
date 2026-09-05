<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\LessonLogPeriodBoard;
use PHPUnit\Framework\TestCase;

/**
 * The four decisions the cahier de texte's period screen makes before rendering anything: which of
 * the two lists it shows, which week it shows, which séance is previewed, and what is unfolded.
 *
 * None of them is visible from the screen once it has settled, and all four are what an incoming
 * link is asking for: `?class=` says « that class, grouped by class », `?date=` says « that day,
 * chronological ». They are tested on plain values rather than on entities because that is all they
 * ever look at - an id, a day, a mode.
 */
class LessonLogPeriodBoardTest extends TestCase
{
    private LessonLogPeriodBoard $board;

    protected function setUp(): void
    {
        $this->board = new LessonLogPeriodBoard();
    }

    /**
     * Three séances over two days and two classes, in the order the screen receives them: by day,
     * then by hour.
     *
     * @return list<array{id: int, classId: int, day: string}>
     */
    private function rows(): array
    {
        return [
            ['id' => 11, 'classId' => 1, 'day' => '2026-08-31'],
            ['id' => 12, 'classId' => 2, 'day' => '2026-08-31'],
            ['id' => 13, 'classId' => 1, 'day' => '2026-09-02'],
        ];
    }

    // --- Which list the left column shows ---

    public function testTheDefaultIsGroupedByClass(): void
    {
        self::assertSame(LessonLogPeriodBoard::MODE_CLASS, $this->board->viewMode(null, null, null, null));
    }

    public function testArrivingWithADateOpensTheChronologicalList(): void
    {
        self::assertSame(LessonLogPeriodBoard::MODE_CHRONOLOGICAL, $this->board->viewMode(null, null, '2026-09-02', null));
    }

    public function testArrivingWithAClassStaysOnTheByClassList(): void
    {
        self::assertSame(LessonLogPeriodBoard::MODE_CLASS, $this->board->viewMode(null, 2, null, null));
    }

    public function testADayIsAskedForChronologicallyEvenAlongsideAClass(): void
    {
        // Naming both is not a contradiction - the class only says which accordion opens - but the
        // list has to be one of the two, and a day is what the chronological one is for.
        self::assertSame(LessonLogPeriodBoard::MODE_CHRONOLOGICAL, $this->board->viewMode(null, 2, '2026-09-02', null));
    }

    public function testAnExplicitModeWinsOverEverything(): void
    {
        self::assertSame(LessonLogPeriodBoard::MODE_CLASS, $this->board->viewMode('class', null, '2026-09-02', 'chronological'));
        self::assertSame(LessonLogPeriodBoard::MODE_CHRONOLOGICAL, $this->board->viewMode('chronological', 2, null, 'class'));
    }

    public function testTheRememberedModeOnlyAnswersWhenNothingElseDoes(): void
    {
        self::assertSame(LessonLogPeriodBoard::MODE_CHRONOLOGICAL, $this->board->viewMode(null, null, null, 'chronological'));
        // A class in the URL is a fresh instruction and outranks what the last visit left behind.
        self::assertSame(LessonLogPeriodBoard::MODE_CLASS, $this->board->viewMode(null, 2, null, 'chronological'));
    }

    public function testAnUnreadableModeIsIgnoredRatherThanShownEmpty(): void
    {
        self::assertSame(LessonLogPeriodBoard::MODE_CLASS, $this->board->viewMode('sideways', null, null, null));
        self::assertSame(LessonLogPeriodBoard::MODE_CLASS, $this->board->viewMode(null, null, null, 'sideways'));
    }

    // --- Which week is on display ---

    public function testTheWeekOpensOnTheCurrentOneEvenWhenItHasNoLesson(): void
    {
        // Deliberately not a sliding seven-day window, and deliberately no jump to the next week
        // that has class: the screen answers « this week », and an empty week is an answer.
        $week = $this->board->weekStart(null, null, new \DateTimeImmutable('2026-09-05'));

        self::assertSame('2026-08-31', $week->format('Y-m-d'));
    }

    public function testAnyDayOfTheWeekAsksForTheSameWeek(): void
    {
        foreach (['2026-08-31', '2026-09-03', '2026-09-06'] as $day) {
            self::assertSame('2026-08-31', $this->board->weekStart($day, null, new \DateTimeImmutable('2026-01-01'))->format('Y-m-d'));
        }
    }

    public function testADateBringsItsOwnWeekAlongWithIt(): void
    {
        $week = $this->board->weekStart(null, '2026-09-02', new \DateTimeImmutable('2026-11-30'));

        self::assertSame('2026-08-31', $week->format('Y-m-d'));
    }

    public function testAnExplicitWeekWinsOverTheDate(): void
    {
        // The ‹ › arrows move the week while the incoming date stays in the URL; without this the
        // period would spring back to the date at every click.
        $week = $this->board->weekStart('2026-09-07', '2026-09-02', new \DateTimeImmutable('2026-11-30'));

        self::assertSame('2026-09-07', $week->format('Y-m-d'));
    }

    public function testAnUnreadableWeekFallsBackInsteadOfFailing(): void
    {
        $week = $this->board->weekStart('not-a-date', null, new \DateTimeImmutable('2026-09-05'));

        self::assertSame('2026-08-31', $week->format('Y-m-d'));
    }

    public function testTheWeekIsAlwaysMidnight(): void
    {
        self::assertSame('00:00:00', $this->board->weekStart(null, '2026-09-02 17:45:00', new \DateTimeImmutable('now'))->format('H:i:s'));
    }

    // --- Which séance is previewed ---

    public function testTheFirstSeanceOfThePeriodIsPreviewedByDefault(): void
    {
        self::assertSame(11, $this->board->selectedSession($this->rows(), null, null, null));
    }

    public function testAnAskedForSeanceIsPreviewed(): void
    {
        self::assertSame(13, $this->board->selectedSession($this->rows(), 13, null, null));
    }

    public function testASeanceOutsideThePeriodFallsBackToTheFirstOne(): void
    {
        // What the ‹ › arrows produce: the week moves, the seance= of the previous week stays in
        // the link and no longer names anything on screen.
        self::assertSame(11, $this->board->selectedSession($this->rows(), 99, null, null));
    }

    public function testADateSelectsTheFirstSeanceOfThatDay(): void
    {
        self::assertSame(13, $this->board->selectedSession($this->rows(), null, null, '2026-09-02'));
    }

    public function testAClassSelectsItsFirstSeanceOfThePeriod(): void
    {
        self::assertSame(12, $this->board->selectedSession($this->rows(), null, 2, null));
    }

    public function testADateWithoutSeanceSelectsNothing(): void
    {
        // The screen has a message for this rather than a preview of an unrelated séance.
        self::assertNull($this->board->selectedSession($this->rows(), null, null, '2026-09-01'));
    }

    public function testAnEmptyPeriodSelectsNothing(): void
    {
        self::assertNull($this->board->selectedSession([], null, null, null));
    }

    // --- What is unfolded ---

    public function testTheClassAskedForIsTheOneOpen(): void
    {
        self::assertSame(2, $this->board->openClass($this->rows(), 2, 11));
    }

    public function testWithoutAClassTheOneCarryingThePreviewedSeanceOpens(): void
    {
        self::assertSame(1, $this->board->openClass($this->rows(), null, 13));
    }

    public function testAClassWithNoSeanceThisWeekCannotBeTheOneOpen(): void
    {
        // It has no header in the list at all - « une classe sans séance n'apparaît pas ».
        self::assertSame(1, $this->board->openClass($this->rows(), 7, 11));
    }

    public function testWithNothingToGoOnTheFirstClassOpens(): void
    {
        self::assertSame(1, $this->board->openClass($this->rows(), null, null));
        self::assertNull($this->board->openClass([], null, null));
    }

    public function testOnlyTheFirstDayOfThePeriodIsUnfolded(): void
    {
        self::assertSame(['2026-08-31'], $this->board->openDays($this->rows(), null, null));
    }

    public function testTheDayAskedForIsTheOneUnfolded(): void
    {
        self::assertSame(['2026-09-02'], $this->board->openDays($this->rows(), '2026-09-02', null));
    }

    public function testTheDayOfThePreviewedSeanceIsUnfolded(): void
    {
        self::assertSame(['2026-09-02'], $this->board->openDays($this->rows(), null, 13));
    }

    public function testADayWithoutSeanceFallsBackToTheFirstOne(): void
    {
        // Nothing would be unfolded otherwise, and the screen would read as an empty week when it
        // is only that one day that is empty.
        self::assertSame(['2026-08-31'], $this->board->openDays($this->rows(), '2026-09-01', null));
    }

    public function testAnEmptyPeriodUnfoldsNothing(): void
    {
        self::assertSame([], $this->board->openDays([], '2026-09-01', null));
    }

    // --- Was the day that was asked for actually empty ---

    public function testAnEmptyDayIsReported(): void
    {
        self::assertTrue($this->board->isDateWithoutSession($this->rows(), '2026-09-01'));
    }

    public function testADayThatHasSeancesIsNotReportedAsEmpty(): void
    {
        self::assertFalse($this->board->isDateWithoutSession($this->rows(), '2026-09-02'));
    }

    public function testNoDateAskedForMeansNothingToReport(): void
    {
        self::assertFalse($this->board->isDateWithoutSession([], null));
    }
}
