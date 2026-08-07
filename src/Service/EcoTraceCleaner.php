<?php

namespace App\Service;

/**
 * Turns a raw GPS trace into the two figures the results screen quotes from it: the distance
 * actually covered, and the elevation climbed.
 *
 * Both need the noise taken out first. A phone logging a fix every 5 seconds wanders by a few
 * metres even lying still on a bench, and summing every hop fix by fix adds all of that wander to
 * the distance - which then inflates the average speed and every detour ratio built on it. The
 * altitude is noisier still, by a factor of several: an unfiltered climb reads in hundreds of
 * metres on a flat park.
 */
class EcoTraceCleaner
{
    /** A hop shorter than this is GPS wander, not ground covered. */
    private const float MIN_MOVE_METERS = 5.0;

    /** Altitude only counts once it has changed by more than the fix-to-fix noise. */
    private const float MIN_CLIMB_METERS = 3.0;

    public function __construct(
        private readonly EcoDistanceCalculator $distanceCalculator,
    ) {
    }

    /**
     * Distance covered, in metres, ignoring hops too short to be real movement.
     *
     * Takes plain [latitude, longitude] pairs rather than entities, so a caller can bound a leg
     * with its two checkpoints' own coordinates (EcoPerformanceAnalyzer) without inventing rows.
     *
     * @param list<array{float, float}> $points
     */
    public function travelledMeters(array $points): float
    {
        $total = 0.0;
        $anchor = null;

        foreach ($points as $point) {
            if (null === $anchor) {
                $anchor = $point;

                continue;
            }

            $metres = $this->distanceCalculator->distanceMeters($anchor[0], $anchor[1], $point[0], $point[1]);

            // The anchor only moves once the runner has: hops under the threshold accumulate
            // against it instead of being dropped, so a slow real walk still counts in full.
            if ($metres >= self::MIN_MOVE_METERS) {
                $total += $metres;
                $anchor = $point;
            }
        }

        return $total;
    }

    /**
     * Metres climbed and metres descended, or null when no fix carried an altitude - every ping
     * logged before the column existed, and any phone without an altitude fix.
     *
     * @param list<?float> $rawAltitudes
     *
     * @return array{gain: float, loss: float}|null
     */
    public function elevation(array $rawAltitudes): ?array
    {
        $altitudes = array_values(array_filter($rawAltitudes, static fn (?float $altitude): bool => null !== $altitude));

        if (\count($altitudes) < 2) {
            return null;
        }

        $gain = 0.0;
        $loss = 0.0;
        $reference = $altitudes[0];

        foreach ($altitudes as $altitude) {
            $delta = $altitude - $reference;

            if (abs($delta) < self::MIN_CLIMB_METERS) {
                continue;
            }

            $delta > 0 ? $gain += $delta : $loss += abs($delta);
            $reference = $altitude;
        }

        return ['gain' => $gain, 'loss' => $loss];
    }
}
