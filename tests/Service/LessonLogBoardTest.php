<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\LessonLogBoard;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * What HugeRTE leaves behind when a teacher types something and deletes it. The badge used to
     * read these as a kept log, on the one screen whose whole purpose is spotting the gaps.
     *
     * @return iterable<string, array{string}>
     */
    public static function emptyLookingContentProvider(): iterable
    {
        yield 'non-breaking space entity' => ['<p>&nbsp;</p>'];
        yield 'numeric non-breaking space' => ['<p>&#160;</p>'];
        yield 'a decoded non-breaking space' => ["<p>\u{00A0}</p>"];
        yield 'a lone line break' => ['<p><br></p>'];
        yield 'several empty paragraphs' => ['<p>&nbsp;</p><p><br></p><p> </p>'];
        yield 'a zero-width space' => ["<p>\u{200B}</p>"];
    }

    #[DataProvider('emptyLookingContentProvider')]
    public function testContentThatOnlyLooksFilledReadsAsEmpty(string $content): void
    {
        self::assertSame('empty', $this->board->stateOf($content));
    }

    public function testARealNonBreakingSpaceInsideTextStillCounts(): void
    {
        // Fixing the above must not go the other way: French typography puts a non-breaking space
        // before a colon, and that sentence is a kept log.
        self::assertSame('filled', $this->board->stateOf("<p>Chapitre 3\u{00A0}: les boucles</p>"));
    }

    // --- The three-state tag of the period screen ---

    public function testASeanceIsFilledWhenWhatWasTaughtIsWrittenDown(): void
    {
        // Same authority as the two-state badge above: only the « pendant » part says the log was
        // kept. What is added here is a middle state, not a second definition of « rempli ».
        self::assertSame('filled', $this->board->sessionStateOf('', '<p>Boucles imbriquées</p>', '', false));
        self::assertSame('filled', $this->board->sessionStateOf('', '<p>Boucles imbriquées</p>', '', true));
    }

    public function testASeanceIsPartialWhenSomethingWasStartedButNotTheAccountOfTheLesson(): void
    {
        self::assertSame('partial', $this->board->sessionStateOf('<p>Lire le chapitre 3</p>', '', '', false));
        self::assertSame('partial', $this->board->sessionStateOf('', '', '<p>Compte rendu de TP</p>', false));
        // A document or an assignment hung on the séance is a start too, with nothing typed.
        self::assertSame('partial', $this->board->sessionStateOf('', '', '', true));
    }

    public function testASeanceIsEmptyWhenNothingWasEverPutOnIt(): void
    {
        self::assertSame('empty', $this->board->sessionStateOf(null, null, null, false));
        self::assertSame('empty', $this->board->sessionStateOf('<p>&nbsp;</p>', '<p><br></p>', '   ', false));
    }

    public function testASectionReadsTheSameThreeStatesOnItsOwnContent(): void
    {
        self::assertSame('filled', $this->board->sectionStateOf('<p>TP VLAN</p>', false));
        // Nothing typed, but a document or an assignment is hanging there: the part is not blank.
        self::assertSame('partial', $this->board->sectionStateOf('<p>&nbsp;</p>', true));
        self::assertSame('empty', $this->board->sectionStateOf(null, false));
    }
}
