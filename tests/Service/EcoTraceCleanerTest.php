<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\EcoPositionPing;
use App\Entity\EcoRunner;
use App\Service\EcoDistanceCalculator;
use App\Service\EcoTraceCleaner;
use PHPUnit\Framework\TestCase;

/**
 * Which fixes of a trace get read at all: the ones the phone did not call vague, and the ones that
 * do not ask the runner to have gone faster than anybody runs through a wood.
 */
class EcoTraceCleanerTest extends TestCase
{
    /** About 11 m of latitude. */
    private const float STEP = 0.0001;

    private EcoTraceCleaner $cleaner;

    protected function setUp(): void
    {
        $this->cleaner = new EcoTraceCleaner(new EcoDistanceCalculator());
    }

    public function testAWalkIsKeptWhole(): void
    {
        // ~11 m every 5 s: 8 km/h.
        $pings = [];
        for ($i = 0; $i < 6; ++$i) {
            $pings[] = $this->ping($i * 5, $i * self::STEP);
        }

        self::assertSame($pings, $this->cleaner->plausible($pings));
    }

    public function testAFixThePhoneCallsVagueIsNotRead(): void
    {
        $pings = [
            $this->ping(0, 0.0, accuracy: 8.0),
            $this->ping(5, self::STEP, accuracy: EcoTraceCleaner::MAX_ACCURACY_METERS + 1),
            $this->ping(10, 2 * self::STEP, accuracy: EcoTraceCleaner::MAX_ACCURACY_METERS),
            // An app that sends no accuracy is not penalised for it.
            $this->ping(15, 3 * self::STEP),
        ];

        self::assertSame([$pings[0], $pings[2], $pings[3]], $this->cleaner->plausible($pings));
    }

    public function testAJumpThereAndBackIsTakenOut(): void
    {
        // Walking north, one fix thrown ~110 m east in 5 s (80 km/h), then back on the line.
        $pings = [
            $this->ping(0, 0.0),
            $this->ping(5, self::STEP),
            $this->ping(10, 2 * self::STEP, longitude: 0.0014),
            $this->ping(15, 3 * self::STEP),
            $this->ping(20, 4 * self::STEP),
        ];

        self::assertSame([$pings[0], $pings[1], $pings[3], $pings[4]], $this->cleaner->plausible($pings));
        // What the jump would have cost: ~220 m on a 44 m walk.
        self::assertEqualsWithDelta(44.5, $this->metresOf($this->cleaner->plausible($pings)), 1.0);
        self::assertGreaterThan(200.0, $this->metresOf($pings));
    }

    public function testTheSpeedIsReadFromTheLastFixKeptNotTheLastFixSeen(): void
    {
        // Two jumps in a row: the second is judged against the fix before the first, not against it.
        $pings = [
            $this->ping(0, 0.0),
            $this->ping(5, 0.0, longitude: 0.0014),
            $this->ping(10, 0.0, longitude: 0.0015),
            $this->ping(15, self::STEP),
        ];

        self::assertSame([$pings[0], $pings[3]], $this->cleaner->plausible($pings));
    }

    public function testAfterThreeJumpsInARowTheTraceIsTakenAsItNowIs(): void
    {
        // The runner really is elsewhere (or the fix everything was measured against was the bad
        // one): refusing for ever would drop the rest of the race.
        $pings = [$this->ping(0, 0.0)];
        for ($i = 1; $i <= 6; ++$i) {
            $pings[] = $this->ping($i * 5, 0.01 + $i * self::STEP);
        }

        self::assertSame([$pings[0], $pings[4], $pings[5], $pings[6]], $this->cleaner->plausible($pings));
    }

    public function testALongSilenceIsNotAJump(): void
    {
        // 300 m in two minutes, the phone quiet in between: 9 km/h.
        $pings = [$this->ping(0, 0.0), $this->ping(120, 0.0027)];

        self::assertSame($pings, $this->cleaner->plausible($pings));
    }

    private function ping(int $second, float $latitude, float $longitude = 0.0, ?float $accuracy = null): EcoPositionPing
    {
        return new EcoPositionPing(
            $this->createStub(EcoRunner::class),
            new \DateTimeImmutable('2026-09-29 10:00:00')->modify('+'.$second.' seconds'),
            45.0 + $latitude,
            1.0 + $longitude,
            null,
            $accuracy,
        );
    }

    /** @param list<EcoPositionPing> $pings */
    private function metresOf(array $pings): float
    {
        return $this->cleaner->travelledMeters(array_map(
            static fn (EcoPositionPing $ping): array => [(float) $ping->getLatitude(), (float) $ping->getLongitude()],
            $pings,
        ));
    }
}
