<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\VideoRetention;
use App\Service\VideoRetentionPoint;
use PHPUnit\Framework\TestCase;

/**
 * The retention map of the teacher's follow-up screen: "passages réellement vus - part de la classe,
 * minute par minute", and the moment the class dropped off.
 *
 * This is what a video gives that an audio recording could not: on a two-minute file there is no
 * "moment" where one drops off. The whole reading rests on one stored number per student - the
 * furthest point they reached watching contiguously - so it is pure arithmetic on primitives, and
 * the only place in the chantier where a wrong curve would be believed rather than noticed.
 */
class VideoRetentionTest extends TestCase
{
    private VideoRetention $retention;

    protected function setUp(): void
    {
        $this->retention = new VideoRetention();
    }

    public function testTheCurveIsCutIntoAsManySegmentsAsAsked(): void
    {
        $curve = $this->retention->curve([100, 50], 600, 10);

        self::assertCount(10, $curve->points);
        self::assertSame(0, $curve->points[0]->startSeconds);
        self::assertSame(60, $curve->points[0]->endSeconds);
        self::assertSame(600, $curve->points[9]->endSeconds);
    }

    /**
     * A segment counts a student only once they watched THROUGH it: someone who stopped in the
     * middle of a minute did not see that minute, and counting them would smooth over exactly the
     * drop-off the map is there to show.
     */
    public function testASegmentOnlyCountsTheStudentsWhoWatchedItThrough(): void
    {
        // 600 seconds, ten segments of one minute. One student stopped at 50%, that is 300s: they
        // saw the first five segments whole and nothing after.
        $curve = $this->retention->curve([100, 50], 600, 10);

        self::assertSame(2, $curve->points[4]->count, 'the fifth minute ends at 300s, reached by both');
        self::assertSame(1, $curve->points[5]->count, 'the sixth ends at 360s, reached by one');
    }

    public function testTheShareIsTakenOverTheWholeTargetedClassNotOverTheWatchers(): void
    {
        // Four students targeted, two of whom never started: half the class saw the first minute,
        // and the map must say half - a share over the watchers alone would read 100%.
        $curve = $this->retention->curve([100, 100, 0, 0], 600, 10);

        self::assertSame(50, $curve->points[0]->sharePercent);
    }

    public function testAVideoNobodyStartedIsAFlatZero(): void
    {
        $curve = $this->retention->curve([0, 0, 0], 600, 10);

        self::assertSame([0, 0, 0], array_map(static fn (VideoRetentionPoint $p): int => $p->count, \array_slice($curve->points, 0, 3)));
        self::assertNull($curve->dropOff);
    }

    /**
     * The drop-off is the single boundary where most of the class left. It is named by the second it
     * happens at, which is what the screen writes ("Décrochage à 08:12").
     */
    public function testTheDropOffNamesWhereTheClassLeft(): void
    {
        // Eleven students up to 480s (80% of 600), three of whom stop there.
        $percents = array_merge(array_fill(0, 8, 100), array_fill(0, 3, 80), [0, 0, 0]);

        $curve = $this->retention->curve($percents, 600, 10);

        self::assertNotNull($curve->dropOff);
        self::assertSame(480, $curve->dropOff->atSeconds);
        self::assertSame(11, $curve->dropOff->before);
        self::assertSame(8, $curve->dropOff->after);
    }

    /**
     * A single student leaving is not a drop-off, it is a student. Announcing one on every video
     * would make the sentence worthless - it is only shown when it says something about the class.
     */
    public function testOneStudentLeavingIsNotADropOff(): void
    {
        $percents = array_merge(array_fill(0, 9, 100), [80]);

        self::assertNull($this->retention->curve($percents, 600, 10)->dropOff);
    }

    public function testAVideoWatchedThroughByEverybodyHasNoDropOff(): void
    {
        self::assertNull($this->retention->curve([100, 100, 100], 600, 10)->dropOff);
    }

    /**
     * A file whose duration was never measured cannot be laid on a timeline: the screen shows no map
     * rather than a curve over a zero-second video.
     */
    public function testAVideoWithNoKnownDurationHasNoCurve(): void
    {
        $curve = $this->retention->curve([100, 50], 0, 10);

        self::assertSame([], $curve->points);
        self::assertNull($curve->dropOff);
    }

    public function testNobodyTargetedIsNotADivisionByZero(): void
    {
        $curve = $this->retention->curve([], 600, 10);

        self::assertCount(10, $curve->points);
        self::assertSame(0, $curve->points[0]->sharePercent);
    }
}
