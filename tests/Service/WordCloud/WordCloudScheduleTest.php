<?php

declare(strict_types=1);

namespace App\Tests\Service\WordCloud;

use App\Enum\WordCloudStatus;
use App\Service\WordCloud\WordCloudSchedule;
use App\Service\WordCloud\WordCloudWindow;
use PHPUnit\Framework\TestCase;

/**
 * `Programmé` → `Ouvert` → `Clos`, read off the dates rather than stored.
 *
 * This is what the server checks before accepting a word, so every line here is also a line about
 * refusing one: « hors période, la soumission étudiante est refusée côté serveur, pas seulement
 * masquée côté client ».
 */
class WordCloudScheduleTest extends TestCase
{
    private const string NOW = '2026-09-08 09:00:00';

    public function testBeforeItsOpeningTimeACloudIsScheduled(): void
    {
        self::assertSame(WordCloudStatus::Scheduled, $this->statusOf($this->window('2026-09-08 09:30', '2026-09-08 10:00')));
    }

    public function testInsideItsWindowACloudIsOpen(): void
    {
        self::assertSame(WordCloudStatus::Open, $this->statusOf($this->window('2026-09-08 08:30', '2026-09-08 09:15')));
    }

    public function testPastItsClosingTimeACloudIsClosed(): void
    {
        self::assertSame(WordCloudStatus::Closed, $this->statusOf($this->window('2026-09-08 08:00', '2026-09-08 08:45')));
    }

    /** The bounds themselves are inside the window: a cloud closing at 09:00 still takes a word at 09:00. */
    public function testTheBoundsAreInclusive(): void
    {
        self::assertSame(WordCloudStatus::Open, $this->statusOf($this->window('2026-09-08 09:00', '2026-09-08 09:15')));
        self::assertSame(WordCloudStatus::Open, $this->statusOf($this->window('2026-09-08 08:45', '2026-09-08 09:00')));
    }

    /** « Ouverture manuelle » : the clock never opens it, whatever dates were entered. */
    public function testAManualCloudStaysScheduledUntilTheTeacherOpensIt(): void
    {
        $window = new WordCloudWindow($this->at('2026-09-08 08:00'), null, true);

        self::assertSame(WordCloudStatus::Scheduled, $this->statusOf($window));
    }

    public function testAManualCloudIsOpenOnceOpenedAndHasNoEndOfItsOwn(): void
    {
        $window = new WordCloudWindow(null, null, true, $this->at('2026-09-08 08:55'));

        self::assertSame(WordCloudStatus::Open, $this->statusOf($window));
        self::assertNull($this->schedule()->remainingSeconds($window, $this->at(self::NOW)));
    }

    public function testAManualCloudGivenAnEndStillClosesOnIt(): void
    {
        $window = new WordCloudWindow(null, $this->at('2026-09-08 08:50'), true, $this->at('2026-09-08 08:30'));

        self::assertSame(WordCloudStatus::Closed, $this->statusOf($window));
    }

    /** « Clore les soumissions » wins over everything, including a window still running. */
    public function testClosingByHandEndsACloudInsideItsOwnWindow(): void
    {
        $window = new WordCloudWindow($this->at('2026-09-08 08:30'), $this->at('2026-09-08 09:15'), false, null, $this->at('2026-09-08 08:52'));

        self::assertSame(WordCloudStatus::Closed, $this->statusOf($window));
    }

    public function testRemainingSecondsCountDownToTheClosingTime(): void
    {
        self::assertSame(360, $this->schedule()->remainingSeconds($this->window('2026-09-08 08:30', '2026-09-08 09:06'), $this->at(self::NOW)));
    }

    public function testRemainingSecondsNeverGoNegative(): void
    {
        self::assertSame(0, $this->schedule()->remainingSeconds($this->window('2026-09-08 08:00', '2026-09-08 08:45'), $this->at(self::NOW)));
    }

    /** « Prolonger 5 min » pushes the end back from where it stood, so two presses buy ten minutes. */
    public function testExtendingPushesTheClosingTimeBack(): void
    {
        $extended = $this->schedule()->extendedClosingTime($this->window('2026-09-08 08:30', '2026-09-08 09:06'), $this->at(self::NOW), 5);

        self::assertSame('2026-09-08 09:11:00', $extended?->format('Y-m-d H:i:s'));
    }

    /**
     * An end already gone by is caught up to first: a cloud that closed at 08:45 and is reopened by
     * « Prolonger 5 min » at 09:00 runs until 09:05, not until 08:50 - which would have been no
     * reopening at all.
     */
    public function testExtendingAnAlreadyClosedWindowStartsFromNow(): void
    {
        $extended = $this->schedule()->extendedClosingTime($this->window('2026-09-08 08:00', '2026-09-08 08:45'), $this->at(self::NOW), 5);

        self::assertSame('2026-09-08 09:05:00', $extended?->format('Y-m-d H:i:s'));
    }

    /** Nothing to push: a cloud with no end is not made to have one by a button meant to delay it. */
    public function testExtendingACloudWithNoEndChangesNothing(): void
    {
        $window = new WordCloudWindow(null, null, true, $this->at('2026-09-08 08:55'));

        self::assertNull($this->schedule()->extendedClosingTime($window, $this->at(self::NOW), 5));
    }

    private function statusOf(WordCloudWindow $window): WordCloudStatus
    {
        return $this->schedule()->status($window, $this->at(self::NOW));
    }

    private function window(string $opensAt, string $closesAt): WordCloudWindow
    {
        return new WordCloudWindow($this->at($opensAt), $this->at($closesAt));
    }

    private function at(string $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment);
    }

    private function schedule(): WordCloudSchedule
    {
        return new WordCloudSchedule();
    }
}
