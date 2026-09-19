<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use App\Entity\VideoResourceFile;
use App\Entity\VideoWatchProgress;
use PHPUnit\Framework\TestCase;

/**
 * The ratchet behind a video's watch tracking.
 *
 * The same rule as AudioListenProgress, and tested for the same reason: what is stored is the
 * furthest point ever reached, so a student who replays or rewinds must never look to their teacher
 * like they went backwards.
 */
class VideoWatchProgressTest extends TestCase
{
    public function testProgressStartsAtZeroAndUnwatched(): void
    {
        $progress = $this->progress();

        self::assertSame(0, $progress->getMaxWatchedPercent());
        self::assertFalse($progress->isComplete());
        self::assertNull($progress->getLastWatchedAt());
    }

    public function testProgressMovesForward(): void
    {
        $progress = $this->progress();
        $progress->registerProgress(35);

        self::assertSame(35, $progress->getMaxWatchedPercent());
        self::assertNotNull($progress->getLastWatchedAt());
    }

    /** A rewind costs nothing: the maximum is what was ever reached, not where the playhead is. */
    public function testProgressNeverGoesBackDown(): void
    {
        $progress = $this->progress();
        $progress->registerProgress(60);
        $progress->registerProgress(12);

        self::assertSame(60, $progress->getMaxWatchedPercent());
    }

    /** A player reporting nonsense must not be able to store it. */
    public function testReportedPercentagesAreClamped(): void
    {
        $progress = $this->progress();
        $progress->registerProgress(140);
        self::assertSame(100, $progress->getMaxWatchedPercent());

        $fresh = $this->progress();
        $fresh->registerProgress(-20);
        self::assertSame(0, $fresh->getMaxWatchedPercent());
    }

    /** Completion of a Watching assignment reads this and nothing else. */
    public function testAFullyWatchedFileIsComplete(): void
    {
        $progress = $this->progress();
        $progress->registerProgress(99);
        self::assertFalse($progress->isComplete());

        $progress->registerProgress(100);
        self::assertTrue($progress->isComplete());
    }

    /** Even a report that changes nothing says the student came back to it. */
    public function testEveryReportRefreshesTheLastWatchedDate(): void
    {
        $progress = $this->progress();
        $progress->registerProgress(80);
        $first = $progress->getLastWatchedAt();

        $progress->registerProgress(10);

        self::assertNotNull($first);
        self::assertGreaterThanOrEqual($first, $progress->getLastWatchedAt());
    }

    /** Reaching 100 % is stamped once: a replay a week later must not move the completion date. */
    public function testCompletionIsStampedOnce(): void
    {
        $progress = $this->progress();
        self::assertNull($progress->getCompletedAt());

        $progress->registerProgress(100);
        $completedAt = $progress->getCompletedAt();
        self::assertNotNull($completedAt);

        usleep(1000);
        $progress->registerProgress(100);

        self::assertSame($completedAt, $progress->getCompletedAt());
        self::assertNotSame($completedAt, $progress->getLastWatchedAt());
    }

    public function testTheWatchingDetailAccumulates(): void
    {
        $progress = $this->progress();
        $progress->addWatchedSeconds(5);
        $progress->addWatchedSeconds(7);
        $progress->addWatchedSeconds(-3);
        $progress->countSkip();
        $progress->countFocusLoss();
        $progress->countFocusLoss();

        self::assertSame(12, $progress->getWatchedSeconds());
        self::assertSame(1, $progress->getSkipCount());
        self::assertSame(2, $progress->getFocusLossCount());
    }

    private function progress(): VideoWatchProgress
    {
        return new VideoWatchProgress($this->createStub(VideoResourceFile::class), new User('student'));
    }
}
