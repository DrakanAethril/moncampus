<?php

declare(strict_types=1);

namespace App\Service\Eco;

use App\Entity\EcoCheckpoint;
use App\Entity\EcoParcours;
use App\Service\EcoDistanceCalculator;
use App\Service\Ign\IgnGeoplateformeClient;
use App\Service\Ign\IgnUnavailableException;

/**
 * What the IGN says about a parcours before anybody runs it - the teacher's reading of the ground
 * they have laid out:
 *
 * - **per leg**, in the order of the flags: the straight line, the climb and the steepest stretch
 *   along it, the share of it that crosses a wood, and the shortest walk the paths allow. Together
 *   they say whether a leg is long, steep, technical or trivially followed by a track;
 * - **per flag**: the nearest road a rescue vehicle can use and the nearest water - the safety
 *   sheet - and the public forest it stands in, which calls for an authorisation from its manager;
 * - **the place**: the commune and the nearest named spot, to say where this is.
 *
 * The result is stamped with EcoParcours::locationFingerprint(), so a flag moved afterwards shows
 * the analysis as out of date. A route the router could not give leaves its leg without one and
 * the analysis marked incomplete; only the BD TOPO features are required, and their absence
 * throws - the request stays pending and the next pass tries again.
 *
 * `routes` holds the shortest walk between two flags keyed like EcoPerformanceAnalyzer's legs
 * ("12-15", direction-agnostic): the consecutive legs here, and the pairs runners actually ran,
 * which EcoPingTerrainResolver adds after a race in free order.
 *
 * @phpstan-type EcoTerrainLeg array{
 *     fromId: int,
 *     toId: int,
 *     fromLabel: string,
 *     toLabel: string,
 *     straightMeters: float,
 *     climbMeters: ?float,
 *     descentMeters: ?float,
 *     maxSlopePercent: ?float,
 *     forestShare: ?float,
 *     pathMeters: ?float,
 *     pathRatio: ?float,
 *     effortKm: ?float,
 * }
 * @phpstan-type EcoTerrainCheckpoint array{
 *     id: int,
 *     label: string,
 *     nearestCarRoadMeters: ?float,
 *     nearestWaterMeters: ?float,
 *     publicForest: ?string,
 * }
 * @phpstan-type EcoTerrainAnalysis array{
 *     fingerprint: string,
 *     commune: ?string,
 *     nearbyPlace: ?string,
 *     publicForests: list<string>,
 *     legs: list<EcoTerrainLeg>,
 *     checkpoints: list<EcoTerrainCheckpoint>,
 *     routes: array<string, float|null>,
 *     incomplete: bool,
 * }
 */
class EcoParcoursTerrainAnalyzer
{
    /** Points along a straight line are sampled this far apart for its profile. */
    private const float SAMPLE_METERS = 10.0;

    /** A change of altitude smaller than this along a leg is the terrain model's own grain. */
    private const float MIN_CLIMB_METERS = 1.0;

    /** Slope is measured over this span: shorter, a single bank reads as a cliff. */
    private const float SLOPE_SPAN_METERS = 20.0;

    public function __construct(
        private readonly IgnGeoplateformeClient $client,
        private readonly EcoTerrainMapLoader $mapLoader,
        private readonly EcoCheckpointTerrainReader $checkpointReader,
        private readonly EcoDistanceCalculator $distanceCalculator,
    ) {
    }

    /**
     * Analyses the parcours and records the result on it (flushing is the caller's).
     *
     * @return EcoTerrainAnalysis
     *
     * @throws IgnUnavailableException when the BD TOPO cannot be read
     */
    public function analyze(EcoParcours $parcours): array
    {
        $checkpoints = array_values(array_filter(
            $parcours->getCheckpoints()->toArray(),
            static fn (EcoCheckpoint $checkpoint): bool => $checkpoint->isLocated(),
        ));
        usort($checkpoints, static fn (EcoCheckpoint $a, EcoCheckpoint $b): int => $a->getPosition() <=> $b->getPosition());

        if ([] === $checkpoints) {
            throw new \LogicException('A parcours without a located flag has no ground to analyse.');
        }

        foreach ($checkpoints as $checkpoint) {
            if (null === $checkpoint->getTerrainReadAt()) {
                $this->checkpointReader->read($checkpoint);
            }
        }

        $points = array_map(self::pointOf(...), $checkpoints);
        $map = $this->mapLoader->load($points, EcoTerrainMapLoader::ALL, 400.0);

        $incomplete = false;
        $legs = $this->legs($checkpoints, $map, $incomplete);

        $routes = [];
        foreach ($legs as $leg) {
            $routes[self::pairKey($leg['fromId'], $leg['toId'])] = $leg['pathMeters'];
        }

        $start = $checkpoints[0];
        try {
            $commune = $this->client->communeAt((float) $start->getLatitude(), (float) $start->getLongitude());
        } catch (IgnUnavailableException) {
            $commune = null;
            $incomplete = true;
        }

        $checkpointRows = [];
        $publicForests = [];
        foreach ($checkpoints as $checkpoint) {
            [$latitude, $longitude] = self::pointOf($checkpoint);
            $forest = $map->publicForestAt($latitude, $longitude);
            if (null !== $forest && !\in_array($forest, $publicForests, true)) {
                $publicForests[] = $forest;
            }

            $road = $map->distanceToCarRoad($latitude, $longitude);
            $water = $map->distanceToWater($latitude, $longitude);
            $checkpointRows[] = [
                'id' => (int) $checkpoint->getId(),
                'label' => self::labelOf($checkpoint),
                'nearestCarRoadMeters' => null !== $road ? round($road) : null,
                'nearestWaterMeters' => null !== $water ? round($water) : null,
                'publicForest' => $forest,
            ];
        }

        $analysis = [
            'fingerprint' => $parcours->locationFingerprint(),
            'commune' => $commune,
            'nearbyPlace' => $map->nearestPlaceName(...self::pointOf($start)),
            'publicForests' => $publicForests,
            'legs' => $legs,
            'checkpoints' => $checkpointRows,
            'routes' => $routes,
            'incomplete' => $incomplete,
        ];

        $parcours->recordTerrainAnalysis($analysis, new \DateTimeImmutable());

        return $analysis;
    }

    /** "12-15": the two flag ids in ascending order, like EcoPerformanceAnalyzer's pair key. */
    public static function pairKey(int $firstId, int $secondId): string
    {
        return min($firstId, $secondId).'-'.max($firstId, $secondId);
    }

    /**
     * @param list<EcoCheckpoint> $checkpoints located, in position order
     *
     * @return list<EcoTerrainLeg>
     */
    private function legs(array $checkpoints, EcoTerrainMap $map, bool &$incomplete): array
    {
        // Every leg's straight line is sampled, and all the samples go to the IGN in one request.
        $samplesPerLeg = [];
        $allSamples = [];
        for ($i = 1, $count = \count($checkpoints); $i < $count; ++$i) {
            $samples = $this->samplesAlong(self::pointOf($checkpoints[$i - 1]), self::pointOf($checkpoints[$i]));
            $samplesPerLeg[$i] = [\count($allSamples), \count($samples)];
            array_push($allSamples, ...$samples);
        }

        try {
            $altitudes = [] !== $allSamples ? $this->client->groundAltitudes($allSamples) : [];
        } catch (IgnUnavailableException) {
            $altitudes = [];
            $incomplete = true;
        }

        $legs = [];
        foreach ($samplesPerLeg as $i => [$offset, $length]) {
            $from = $checkpoints[$i - 1];
            $to = $checkpoints[$i];
            [$fromLatitude, $fromLongitude] = self::pointOf($from);
            [$toLatitude, $toLongitude] = self::pointOf($to);
            $straight = $this->distanceCalculator->distanceMeters($fromLatitude, $fromLongitude, $toLatitude, $toLongitude);

            $samples = \array_slice($allSamples, $offset, $length);
            $profile = [] !== $altitudes ? \array_slice($altitudes, $offset, $length) : [];
            $climb = self::climbOf($profile);

            $inForest = \count(array_filter($samples, static fn (array $point): bool => $map->isInForest($point[0], $point[1])));

            try {
                $route = $this->client->pedestrianRoute([$fromLatitude, $fromLongitude], [$toLatitude, $toLongitude]);
            } catch (IgnUnavailableException) {
                $route = null;
                $incomplete = true;
            }
            $pathMeters = null !== $route ? round($route['meters']) : null;

            $legs[] = [
                'fromId' => (int) $from->getId(),
                'toId' => (int) $to->getId(),
                'fromLabel' => self::labelOf($from),
                'toLabel' => self::labelOf($to),
                'straightMeters' => round($straight),
                'climbMeters' => null !== $climb ? round($climb['climb']) : null,
                'descentMeters' => null !== $climb ? round($climb['descent']) : null,
                'maxSlopePercent' => self::maxSlopeOf($profile, $length > 1 ? $straight / ($length - 1) : self::SAMPLE_METERS),
                'forestShare' => [] !== $samples ? round($inForest / \count($samples), 2) : null,
                'pathMeters' => $pathMeters,
                // Below 50 m the ratio says more about where the router snaps a point than about
                // the ground (same threshold as a runner's detour ratio).
                'pathRatio' => null !== $pathMeters && $straight > 50.0 ? round($pathMeters / $straight, 2) : null,
                'effortKm' => null !== $climb ? round($straight / 1000 + $climb['climb'] / 100, 2) : null,
            ];
        }

        return $legs;
    }

    /**
     * Points every SAMPLE_METERS along the straight line, both ends included.
     *
     * @param array{float, float} $from
     * @param array{float, float} $to
     *
     * @return list<array{float, float}>
     */
    private function samplesAlong(array $from, array $to): array
    {
        $distance = $this->distanceCalculator->distanceMeters($from[0], $from[1], $to[0], $to[1]);
        $steps = max(1, min(500, (int) ceil($distance / self::SAMPLE_METERS)));

        $samples = [];
        for ($step = 0; $step <= $steps; ++$step) {
            $t = $step / $steps;
            $samples[] = [$from[0] + ($to[0] - $from[0]) * $t, $from[1] + ($to[1] - $from[1]) * $t];
        }

        return $samples;
    }

    /**
     * Metres climbed and descended along a profile, ignoring the model's grain.
     *
     * @param list<?float> $profile
     *
     * @return array{climb: float, descent: float}|null
     */
    public static function climbOf(array $profile): ?array
    {
        $altitudes = array_values(array_filter($profile, static fn (?float $altitude): bool => null !== $altitude));
        if (\count($altitudes) < 2) {
            return null;
        }

        $climb = 0.0;
        $descent = 0.0;
        $reference = $altitudes[0];
        foreach ($altitudes as $altitude) {
            $delta = $altitude - $reference;
            if (abs($delta) < self::MIN_CLIMB_METERS) {
                continue;
            }
            $delta > 0 ? $climb += $delta : $descent -= $delta;
            $reference = $altitude;
        }

        return ['climb' => $climb, 'descent' => $descent];
    }

    /**
     * The steepest SLOPE_SPAN_METERS of a profile, in percent, up or down alike.
     *
     * @param list<?float> $profile
     * @param float        $spacing metres between two consecutive points of the profile
     */
    public static function maxSlopeOf(array $profile, float $spacing): ?float
    {
        if ($spacing <= 0.0) {
            return null;
        }

        $span = max(1, (int) round(self::SLOPE_SPAN_METERS / $spacing));
        $steepest = null;

        for ($i = $span, $count = \count($profile); $i < $count; ++$i) {
            $from = $profile[$i - $span];
            $to = $profile[$i];
            if (null === $from || null === $to) {
                continue;
            }
            $slope = abs($to - $from) / ($span * $spacing) * 100;
            $steepest = null === $steepest ? $slope : max($steepest, $slope);
        }

        return null !== $steepest ? round($steepest) : null;
    }

    /** @return array{float, float} */
    private static function pointOf(EcoCheckpoint $checkpoint): array
    {
        return [(float) $checkpoint->getLatitude(), (float) $checkpoint->getLongitude()];
    }

    /** D, A, or the flag's number - the labels of every other e-CO table. */
    private static function labelOf(EcoCheckpoint $checkpoint): string
    {
        return $checkpoint->getType()->shortLetter() ?? (string) $checkpoint->getPosition();
    }
}
