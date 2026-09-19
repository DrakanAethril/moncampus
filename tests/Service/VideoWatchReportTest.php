<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\VideoWatchEventType;
use App\Service\JsonRequestPayload;
use App\Service\VideoWatchReport;
use PHPUnit\Framework\TestCase;

/**
 * The reading of a player's report. What it guards is the teacher's screen: a skip or a playing time
 * that no player could have sent must not end up printed next to a student's name.
 */
class VideoWatchReportTest extends TestCase
{
    /** The mobile app installed before the detail existed sends the percentage alone. */
    public function testAPercentageAloneIsStillAReport(): void
    {
        $report = VideoWatchReport::fromPayload(JsonRequestPayload::fromArray(['percent' => 42]), 600);

        self::assertSame(42, $report->percent);
        self::assertSame(0, $report->watchedSeconds);
        self::assertSame([], $report->events);
    }

    public function testSkipsAndFocusLossesAreRead(): void
    {
        $report = VideoWatchReport::fromPayload(JsonRequestPayload::fromArray([
            'percent' => 30,
            'watchedSeconds' => 5.4,
            'events' => [
                ['type' => 'skip', 'from' => 129.6, 'to' => 405.2],
                ['type' => 'focus_loss', 'at' => 212],
            ],
        ]), 600);

        self::assertSame(5, $report->watchedSeconds);
        self::assertSame([
            ['type' => VideoWatchEventType::Skip, 'position' => 130, 'target' => 405],
            ['type' => VideoWatchEventType::FocusLoss, 'position' => 212, 'target' => null],
        ], $report->events);
    }

    /** Going back misses nothing: a « skip » that does not go forward is not recorded. */
    public function testABackwardSkipIsDropped(): void
    {
        $report = VideoWatchReport::fromPayload(JsonRequestPayload::fromArray([
            'percent' => 30,
            'events' => [['type' => 'skip', 'from' => 300, 'to' => 120], ['type' => 'rewind', 'at' => 3]],
        ]), 600);

        self::assertSame([], $report->events);
    }

    public function testPositionsAreBoundedByTheFile(): void
    {
        $report = VideoWatchReport::fromPayload(JsonRequestPayload::fromArray([
            'percent' => 30,
            'events' => [['type' => 'skip', 'from' => -5, 'to' => 9000]],
        ]), 600);

        self::assertSame([['type' => VideoWatchEventType::Skip, 'position' => 0, 'target' => 600]], $report->events);
    }

    public function testAnImplausibleReportIsCapped(): void
    {
        $events = array_fill(0, 50, ['type' => 'focus_loss', 'at' => 10]);
        $report = VideoWatchReport::fromPayload(JsonRequestPayload::fromArray([
            'percent' => 30,
            'watchedSeconds' => 99999,
            'events' => $events,
        ]), 600);

        self::assertSame(VideoWatchReport::MAX_WATCHED_SECONDS, $report->watchedSeconds);
        self::assertCount(VideoWatchReport::MAX_EVENTS, $report->events);
    }
}
