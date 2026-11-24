<?php

declare(strict_types=1);

namespace App\Service;

// Haversine great-circle distance - used both to validate a checkpoint scan against its
// tolerance (App\Controller\Api - mobile scan endpoint, not built in this phase) and to sum a
// runner's GPS trace into a total distance for the results screen (1i).
class EcoDistanceCalculator
{
    private const float EARTH_RADIUS_METERS = 6_371_000.0;

    public function distanceMeters(float $fromLatitude, float $fromLongitude, float $toLatitude, float $toLongitude): float
    {
        $latDelta = deg2rad($toLatitude - $fromLatitude);
        $lonDelta = deg2rad($toLongitude - $fromLongitude);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($fromLatitude)) * cos(deg2rad($toLatitude)) * sin($lonDelta / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_METERS * $c;
    }

    /**
     * Every hop of a line summed as it stands, nothing left out - the raw distance a trace reads
     * before EcoTraceCleaner takes its noise away.
     *
     * @param list<array{float, float}> $points [latitude, longitude] pairs
     */
    public function pathMeters(array $points): float
    {
        $total = 0.0;
        for ($i = 1, $count = \count($points); $i < $count; ++$i) {
            $total += $this->distanceMeters($points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1]);
        }

        return $total;
    }
}
