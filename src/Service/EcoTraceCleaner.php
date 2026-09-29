<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EcoPositionPing;

/**
 * Turns a raw GPS trace into the two figures the results screen quotes from it: the distance
 * actually covered, and the elevation climbed.
 *
 * Both need the noise taken out first. A phone logging a fix every 5 seconds wanders by a few
 * metres even lying still on a bench, and summing every hop fix by fix adds all of that wander to
 * the distance - which then inflates the average speed and every detour ratio built on it. The
 * altitude is noisier still, by a factor of several: an unfiltered climb reads in hundreds of
 * metres on a flat park.
 *
 * Wander is not the only noise. Under trees a phone now and then hands out a fix tens of metres
 * off - a network position, a reflected signal - and the trace jumps there and back: every such
 * jump clears the 5 m threshold and is summed twice. plausible() takes those fixes out before
 * anything reads the trace.
 */
class EcoTraceCleaner
{
    /** A fix the phone itself places no closer than this is not read. */
    public const float MAX_ACCURACY_METERS = 30.0;

    /** Faster than anybody runs through a wood: a fix that far from the last one is a jump. */
    public const float MAX_SPEED_KMH = 25.0;

    /**
     * After this many jumps in a row, the next fix is taken whatever it says: the runner has
     * really moved (a long gap in the trace), or the fix everything was measured against was the
     * bad one, and refusing for ever would drop the rest of the race.
     */
    private const int MAX_REJECTED_IN_A_ROW = 3;

    /** A hop shorter than this is GPS wander, not ground covered. */
    private const float MIN_MOVE_METERS = 5.0;

    /** Altitude only counts once it has changed by more than the fix-to-fix noise. */
    private const float MIN_CLIMB_METERS = 3.0;

    public function __construct(
        private readonly EcoDistanceCalculator $distanceCalculator,
    ) {
    }

    /**
     * The fixes worth reading, in their order: those the phone did not call vague, and those that
     * do not ask the runner to have covered the ground since the last fix kept faster than
     * MAX_SPEED_KMH. Every figure read off a trace starts from this - the distance, the legs, the
     * stops, the terrain split, the line drawn on the map.
     *
     * @param list<EcoPositionPing> $pings in recording order
     *
     * @return list<EcoPositionPing>
     */
    public function plausible(array $pings): array
    {
        $kept = [];
        $last = null;
        $rejectedInARow = 0;

        foreach ($pings as $ping) {
            $accuracy = $ping->getAccuracy();
            if (null !== $accuracy && $accuracy > self::MAX_ACCURACY_METERS) {
                continue;
            }

            if (null !== $last && $rejectedInARow < self::MAX_REJECTED_IN_A_ROW && $this->isJump($last, $ping)) {
                ++$rejectedInARow;

                continue;
            }

            $kept[] = $ping;
            $last = $ping;
            $rejectedInARow = 0;
        }

        return $kept;
    }

    private function isJump(EcoPositionPing $from, EcoPositionPing $to): bool
    {
        $fromAt = $from->getRecordedAt();
        $toAt = $to->getRecordedAt();
        if (null === $fromAt || null === $toAt) {
            return false;
        }

        // Two fixes stamped the same second are still a second apart as far as a pace goes.
        $seconds = max(1, $toAt->getTimestamp() - $fromAt->getTimestamp());
        $metres = $this->distanceCalculator->distanceMeters((float) $from->getLatitude(), (float) $from->getLongitude(), (float) $to->getLatitude(), (float) $to->getLongitude());

        return $metres / $seconds * 3.6 > self::MAX_SPEED_KMH;
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
     * @param float|null   $minClimbMeters the noise to ignore; the IGN's terrain model is far
     *                                     steadier than a phone's altitude and gets a smaller one
     *
     * @return array{gain: float, loss: float}|null
     */
    public function elevation(array $rawAltitudes, ?float $minClimbMeters = null): ?array
    {
        $threshold = $minClimbMeters ?? self::MIN_CLIMB_METERS;
        $altitudes = array_values(array_filter($rawAltitudes, static fn (?float $altitude): bool => null !== $altitude));

        if (\count($altitudes) < 2) {
            return null;
        }

        $gain = 0.0;
        $loss = 0.0;
        $reference = $altitudes[0];

        foreach ($altitudes as $altitude) {
            $delta = $altitude - $reference;

            if (abs($delta) < $threshold) {
                continue;
            }

            $delta > 0 ? $gain += $delta : $loss += abs($delta);
            $reference = $altitude;
        }

        return ['gain' => $gain, 'loss' => $loss];
    }
}
