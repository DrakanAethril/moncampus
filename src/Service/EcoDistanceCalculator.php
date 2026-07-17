<?php

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
}
