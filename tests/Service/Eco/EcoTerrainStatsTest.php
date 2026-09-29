<?php

declare(strict_types=1);

namespace App\Tests\Service\Eco;

use App\Service\Eco\EcoTerrainStats;
use App\Service\EcoDistanceCalculator;
use App\Service\EcoTraceCleaner;
use PHPUnit\Framework\TestCase;

/**
 * What a runner's fixes say once the IGN has read them - and, as much, what they must not say
 * before it has.
 *
 * @phpstan-import-type EcoFix from EcoTerrainStats
 */
class EcoTerrainStatsTest extends TestCase
{
    private EcoTerrainStats $stats;

    protected function setUp(): void
    {
        $distance = new EcoDistanceCalculator();
        $this->stats = new EcoTerrainStats($distance, new EcoTraceCleaner($distance));
    }

    public function testTheClimbIsReadOnTheTerrainModelOnceTheTraceIsResolved(): void
    {
        // The phone swings by ±6 m, the ground climbs a steady 10 m.
        $fixes = [];
        foreach ([300.0, 302.5, 305.0, 307.5, 310.0] as $i => $ground) {
            $fixes[] = $this->fix($i * 5, 0.0001 * $i, ground: $ground, gps: 300.0 + (0 === $i % 2 ? 6.0 : -6.0));
        }

        $elevation = $this->stats->elevation($fixes);

        self::assertNotNull($elevation);
        self::assertSame('ign', $elevation['source']);
        self::assertEqualsWithDelta(10.0, $elevation['gain'], 0.01);
        self::assertEqualsWithDelta(0.0, $elevation['loss'], 0.01);
    }

    public function testAHalfResolvedTraceFallsBackToThePhoneAndSaysSo(): void
    {
        $fixes = [];
        for ($i = 0; $i < 10; ++$i) {
            $fixes[] = $this->fix($i * 5, 0.0001 * $i, ground: 300.0 + $i, gps: 300.0 + 4 * $i, resolved: $i < 5);
        }

        $elevation = $this->stats->elevation($fixes);

        self::assertNotNull($elevation);
        self::assertSame('gps', $elevation['source']);
        self::assertNull($this->stats->split($fixes), 'no terrain split on a trace the IGN has only half read');
    }

    public function testTheSplitIsTimedNotCounted(): void
    {
        // 60 s along a path in the open, then 120 s standing in a wood off any path, logged by
        // only three fixes: time decides, not how many fixes the phone happened to send.
        $fixes = [
            $this->fix(0, 0.0, onPath: true, inForest: false),
            $this->fix(60, 0.001, onPath: false, inForest: true),
            $this->fix(120, 0.001, onPath: false, inForest: true),
            $this->fix(180, 0.001, onPath: false, inForest: true),
        ];

        $split = $this->stats->split($fixes);

        self::assertNotNull($split);
        self::assertEqualsWithDelta(0.67, $split['offPathShare'], 0.01);
        self::assertEqualsWithDelta(0.67, $split['forestShare'], 0.01);
        // 0.001° of latitude in 60 s: ~111 m a minute on the spherical earth, 6.7 km/h.
        self::assertEqualsWithDelta(6.7, (float) $split['onPathSpeedKmh'], 0.05);
        self::assertEqualsWithDelta(0.0, (float) $split['offPathSpeedKmh'], 0.01);
    }

    public function testThePaceIgnoresWanderButKeepsASlowWalk(): void
    {
        // A minute standing at a flag in a wood, the phone wandering 3.3 m back and forth every
        // 5 s - summed raw, 2.3 km/h of walking that never happened...
        $fixes = [];
        for ($i = 0; $i <= 12; ++$i) {
            $fixes[] = $this->fix($i * 5, 0 === $i % 2 ? 0.0 : 0.00003, onPath: false, inForest: true);
        }
        // ...then a slow walk along a path, 2.2 m every 5 s: no single hop clears the threshold,
        // the walk still counts in full.
        for ($k = 0; $k < 12; ++$k) {
            $fixes[] = $this->fix(65 + $k * 5, 0.00002 * ($k + 1), onPath: true, inForest: false);
        }

        $split = $this->stats->split($fixes);

        self::assertNotNull($split);
        self::assertEqualsWithDelta(0.0, (float) $split['offPathSpeedKmh'], 0.01);
        // 26.7 m in 55 s.
        self::assertEqualsWithDelta(1.7, (float) $split['onPathSpeedKmh'], 0.1);
    }

    public function testASilentPhoneCountsForNobody(): void
    {
        $fixes = [
            $this->fix(0, 0.0, onPath: true),
            $this->fix(40, 0.0003, onPath: true),
            // Ten minutes without a fix: the phone was in a pocket, off the network, or the app closed.
            $this->fix(640, 0.005, onPath: false),
            $this->fix(680, 0.0053, onPath: false),
        ];

        $split = $this->stats->split($fixes);

        self::assertNotNull($split);
        self::assertEqualsWithDelta(0.5, $split['offPathShare'], 0.01);
    }

    public function testEffortAddsAKilometrePerHundredMetresClimbed(): void
    {
        self::assertEqualsWithDelta(3.4, EcoTerrainStats::effortKm(2500.0, 90.0), 0.0001);
        self::assertEqualsWithDelta(2.5, EcoTerrainStats::effortKm(2500.0, null), 0.0001);
    }

    /** @return EcoFix */
    private function fix(int $at, float $northOffset, ?float $ground = 300.0, ?float $gps = null, ?bool $onPath = true, ?bool $inForest = false, bool $resolved = true): array
    {
        return [
            'at' => 1_790_000_000 + $at,
            'latitude' => 45.85 + $northOffset,
            'longitude' => 1.23,
            'gpsAltitude' => $gps,
            'groundAltitude' => $resolved ? $ground : null,
            'onPath' => $resolved ? $onPath : null,
            'inForest' => $resolved ? $inForest : null,
            'resolved' => $resolved,
        ];
    }
}
