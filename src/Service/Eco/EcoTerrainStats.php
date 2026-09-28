<?php

declare(strict_types=1);

namespace App\Service\Eco;

use App\Entity\EcoPositionPing;
use App\Service\EcoDistanceCalculator;
use App\Service\EcoTraceCleaner;

/**
 * What a runner's fixes say once the IGN has been asked about them (EcoPingTerrainResolver): the
 * elevation gain on the terrain model, the effort it amounts to, and how the race split between
 * paths and cross-country, woods and open ground.
 *
 * Works on plain fixes rather than entities, so the rules can be tested without a database; the
 * entities go through fixesOf().
 *
 * **Nothing is read from a half-resolved trace.** Until RESOLVED_SHARE of the fixes carry the
 * IGN's answer - a race closed a minute ago, a trace outside France - the elevation falls back to
 * the phone's own altitude, labelled as such, and the terrain split is not given at all: a share
 * worked out on part of a race would be a share of something else.
 *
 * Every split is **timed**, not counted: each stretch between two fixes is given to what the
 * first of them stood on, for as long as it lasted. A runner standing at a flag in a wood for two
 * minutes spent two minutes in the wood, whether the phone logged twenty fixes or three. A stretch
 * longer than MAX_GAP_SECONDS is a phone that said nothing, and counts for nobody - the same rule
 * as the stops of EcoPerformanceAnalyzer.
 *
 * @phpstan-type EcoFix array{at: int, latitude: float, longitude: float, gpsAltitude: ?float, groundAltitude: ?float, onPath: ?bool, inForest: ?bool, resolved: bool}
 * @phpstan-type EcoTerrainSplit array{
 *     offPathShare: float,
 *     forestShare: float,
 *     onPathSpeedKmh: ?float,
 *     offPathSpeedKmh: ?float,
 *     forestSpeedKmh: ?float,
 *     openSpeedKmh: ?float,
 * }
 */
final class EcoTerrainStats
{
    public const float RESOLVED_SHARE = 0.9;

    /** The terrain model is steady: only the noise of the fix's own position is left to ignore. */
    public const float IGN_MIN_CLIMB_METERS = 2.0;

    private const int MAX_GAP_SECONDS = 120;

    /** A speed is only quoted over at least this much time: ten seconds off a path is not a pace. */
    private const int MIN_SPEED_SECONDS = 30;

    public function __construct(
        private readonly EcoDistanceCalculator $distanceCalculator,
        private readonly EcoTraceCleaner $traceCleaner,
    ) {
    }

    /**
     * @param list<EcoPositionPing> $pings
     *
     * @return list<EcoFix>
     */
    public static function fixesOf(array $pings): array
    {
        return array_map(static fn (EcoPositionPing $ping): array => [
            'at' => (int) $ping->getRecordedAt()?->getTimestamp(),
            'latitude' => (float) $ping->getLatitude(),
            'longitude' => (float) $ping->getLongitude(),
            'gpsAltitude' => $ping->getAltitude(),
            'groundAltitude' => $ping->getGroundAltitude(),
            'onPath' => $ping->isOnPath(),
            'inForest' => $ping->isInForest(),
            'resolved' => $ping->isTerrainResolved(),
        ], $pings);
    }

    /** @param list<EcoFix> $fixes */
    public function isResolved(array $fixes): bool
    {
        if ([] === $fixes) {
            return false;
        }

        $resolved = \count(array_filter($fixes, static fn (array $fix): bool => $fix['resolved']));

        return $resolved / \count($fixes) >= self::RESOLVED_SHARE;
    }

    /**
     * Metres climbed and descended - on the IGN's terrain model when the trace has been read, on
     * the phone's altitude otherwise - and which of the two it is.
     *
     * @param list<EcoFix> $fixes
     *
     * @return array{gain: float, loss: float, source: 'ign'|'gps'}|null
     */
    public function elevation(array $fixes): ?array
    {
        if ($this->isResolved($fixes)) {
            $ign = $this->traceCleaner->elevation(array_column($fixes, 'groundAltitude'), self::IGN_MIN_CLIMB_METERS);
            if (null !== $ign) {
                return $ign + ['source' => 'ign'];
            }
        }

        $gps = $this->traceCleaner->elevation(array_column($fixes, 'gpsAltitude'));

        return null !== $gps ? $gps + ['source' => 'gps'] : null;
    }

    /**
     * Kilometre-effort: the distance, plus a kilometre for every hundred metres climbed - the
     * trail runners' measure, which puts a hilly leg and a flat one on the same scale.
     */
    public static function effortKm(float $distanceMeters, ?float $climbMeters): float
    {
        return $distanceMeters / 1000 + ($climbMeters ?? 0.0) / 100;
    }

    /**
     * How the race split between paths and cross-country, woods and open ground, in time, with the
     * pace kept on each. Null until the trace has been read.
     *
     * @param list<EcoFix> $fixes
     *
     * @return EcoTerrainSplit|null
     */
    public function split(array $fixes): ?array
    {
        if (!$this->isResolved($fixes)) {
            return null;
        }

        $seconds = ['onPath' => 0, 'offPath' => 0, 'forest' => 0, 'open' => 0];
        $meters = ['onPath' => 0.0, 'offPath' => 0.0, 'forest' => 0.0, 'open' => 0.0];

        for ($i = 1, $count = \count($fixes); $i < $count; ++$i) {
            $from = $fixes[$i - 1];
            $to = $fixes[$i];
            $elapsed = $to['at'] - $from['at'];

            if ($elapsed <= 0 || $elapsed > self::MAX_GAP_SECONDS || !$from['resolved']) {
                continue;
            }

            $distance = $this->distanceCalculator->distanceMeters($from['latitude'], $from['longitude'], $to['latitude'], $to['longitude']);
            $path = true === $from['onPath'] ? 'onPath' : 'offPath';
            $cover = true === $from['inForest'] ? 'forest' : 'open';

            $seconds[$path] += $elapsed;
            $meters[$path] += $distance;
            $seconds[$cover] += $elapsed;
            $meters[$cover] += $distance;
        }

        $total = $seconds['onPath'] + $seconds['offPath'];
        if (0 === $total) {
            return null;
        }

        $speed = static fn (string $key): ?float => $seconds[$key] >= self::MIN_SPEED_SECONDS
            ? round($meters[$key] / $seconds[$key] * 3.6, 1)
            : null;

        return [
            'offPathShare' => round($seconds['offPath'] / $total, 2),
            'forestShare' => round($seconds['forest'] / $total, 2),
            'onPathSpeedKmh' => $speed('onPath'),
            'offPathSpeedKmh' => $speed('offPath'),
            'forestSpeedKmh' => $speed('forest'),
            'openSpeedKmh' => $speed('open'),
        ];
    }
}
