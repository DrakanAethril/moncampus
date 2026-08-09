<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\LessonLogBoard;
use PHPUnit\Framework\TestCase;

/**
 * The two decisions behind the cahier de texte list: which week it opens on, and what the badge on
 * each row says.
 *
 * Neither is visible from the screen. The week fallback in particular only shows itself when the
 * current week has no lesson at all - during a holiday, a stage period or an alternance week, which
 * is exactly when a teacher opens the screen and finds it either useful or empty.
 */
class LessonLogBoardTest extends TestCase
{
    private LessonLogBoard $board;

    protected function setUp(): void
    {
        $this->board = new LessonLogBoard();
    }

    // --- Snapping a day to its week ---

    public function testAnyDaySnapsToItsMonday(): void
    {
        // The rows are grouped by week, so every day of a week has to answer the same key.
        foreach (['2026-11-02', '2026-11-05', '2026-11-08'] as $day) {
            self::assertSame('2026-11-02', $this->board->weekStartOf(new \DateTimeImmutable($day))->format('Y-m-d'));
        }
    }

    public function testSnappingDropsTheTimeOfDay(): void
    {
        $week = $this->board->weekStartOf(new \DateTimeImmutable('2026-11-05 14:30:00'));

        self::assertSame('2026-11-02 00:00:00', $week->format('Y-m-d H:i:s'));
    }

    // --- Which week to display ---

    public function testAnExplicitWeekWins(): void
    {
        $week = $this->board->weekToDisplay('2026-11-04', ['2026-11-02'], new \DateTimeImmutable('2026-09-07'));

        // Snapped to its Monday: the date picker hands back an arbitrary day.
        self::assertSame('2026-11-02', $week->format('Y-m-d'));
    }

    public function testAnExplicitWeekWinsEvenWhenItHasNoLesson(): void
    {
        $week = $this->board->weekToDisplay('2026-12-25', ['2026-11-02'], new \DateTimeImmutable('2026-11-03'));

        self::assertSame('2026-12-21', $week->format('Y-m-d'), 'the teacher asked for it, empty or not');
    }

    public function testAnUnreadableWeekFallsBackInsteadOfFailing(): void
    {
        $week = $this->board->weekToDisplay('pas-une-date', ['2026-11-02'], new \DateTimeImmutable('2026-11-04'));

        self::assertSame('2026-11-02', $week->format('Y-m-d'));
    }

    public function testTheCurrentWeekIsUsedWhenItCarriesLessons(): void
    {
        $week = $this->board->weekToDisplay('', ['2026-11-02', '2026-11-09'], new \DateTimeImmutable('2026-11-05'));

        self::assertSame('2026-11-02', $week->format('Y-m-d'));
    }

    public function testAWeekWithoutLessonsJumpsForwardToTheNextOneThatHasSome(): void
    {
        // A holiday or an alternance week: land on the next week that actually has something.
        $week = $this->board->weekToDisplay('', ['2026-11-02', '2026-12-07'], new \DateTimeImmutable('2026-11-18'));

        self::assertSame('2026-12-07', $week->format('Y-m-d'));
    }

    public function testPastTheLastLessonItFallsBackToTheLastWeek(): void
    {
        // End of the school year: nothing ahead, so show the last week that had lessons rather
        // than an empty screen.
        $week = $this->board->weekToDisplay('', ['2026-11-02', '2026-12-07'], new \DateTimeImmutable('2027-02-10'));

        self::assertSame('2026-12-07', $week->format('Y-m-d'));
    }

    public function testWithNoLessonAtAllItStaysOnTheCurrentWeek(): void
    {
        $week = $this->board->weekToDisplay('', [], new \DateTimeImmutable('2026-11-05'));

        self::assertSame('2026-11-02', $week->format('Y-m-d'));
    }

    // --- What the badge says ---

    public function testOnlyWhatWasActuallyTaughtDecidesTheBadge(): void
    {
        self::assertSame('empty', $this->board->stateOf(null), 'no log at all');
        self::assertSame('empty', $this->board->stateOf(''));
        self::assertSame('empty', $this->board->stateOf('   '));
        self::assertSame('empty', $this->board->stateOf('<p></p>'), 'markup with no text is not a record');
        self::assertSame('filled', $this->board->stateOf('<p>Boucles imbriquées</p>'));
    }

    public function testAnEmptyParagraphFromTheEditorStillReadsAsFilled(): void
    {
        // Pinned as-is, NOT endorsed: HugeRTE leaves "<p>&nbsp;</p>" behind when a teacher types
        // something and deletes it, and strip_tags() leaves the entity in place. The badge then
        // claims a log is kept when it holds nothing. Changing it would change what the screen
        // shows, so it is reported rather than fixed inside a refactor.
        self::assertSame('filled', $this->board->stateOf('<p>&nbsp;</p>'));
    }
}
