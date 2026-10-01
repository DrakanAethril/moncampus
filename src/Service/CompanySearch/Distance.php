<?php

declare(strict_types=1);

namespace App\Service\CompanySearch;

/**
 * As the crow flies, in kilometres - what « 1,8 km de l'école » means on a result. Never a travel
 * time: that would need a routing service, and the « Itinéraire » link hands that question to the
 * map that can answer it.
 */
final class Distance
{
    private const float EARTH_RADIUS_KM = 6371.0;

    public static function km(float $fromLatitude, float $fromLongitude, float $toLatitude, float $toLongitude): float
    {
        $dLat = deg2rad($toLatitude - $fromLatitude);
        $dLon = deg2rad($toLongitude - $fromLongitude);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($fromLatitude)) * cos(deg2rad($toLatitude)) * sin($dLon / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
