<?php

declare(strict_types=1);

namespace App\Service\Eco;

use App\Service\Ign\IgnGeoplateformeClient;
use App\Service\Ign\IgnUnavailableException;

/**
 * Fetches the BD TOPO layers of an area and hands them to EcoTerrainMap.
 *
 * A WFS page of the Géoplateforme takes several seconds to come back (ten, for the roads of a
 * square kilometre of Limousin, on 2026-09-28), so a caller asks only for what it will read: the
 * GPS fixes of a race need the paths and the woods, the parcours analysis needs everything.
 */
class EcoTerrainMapLoader
{
    /** Paths and roads, for "on a path" and for the nearest road a vehicle can use. */
    public const string ROADS = 'roads';

    /** Woods, for "in a wood" and for a leg's share of forest. */
    public const string VEGETATION = 'vegetation';

    /** Rivers, lakes and ponds, for the safety sheet. */
    public const string WATER = 'water';

    /** Public forests (ONF), which need an authorisation before a race is held in them. */
    public const string PUBLIC_FORESTS = 'public_forests';

    /** Named places, to say where the parcours is. */
    public const string PLACES = 'places';

    public const array ALL = [self::ROADS, self::VEGETATION, self::WATER, self::PUBLIC_FORESTS, self::PLACES];

    public function __construct(
        private readonly IgnGeoplateformeClient $client,
    ) {
    }

    /**
     * @param list<array{float, float}> $points the area is their bounding box, widened by $marginMeters
     * @param list<string>              $layers some of the constants above
     *
     * @throws IgnUnavailableException
     */
    public function load(array $points, array $layers, float $marginMeters = 300.0): EcoTerrainMap
    {
        $box = self::boundingBox($points, $marginMeters);
        $origin = [($box[0] + $box[2]) / 2, ($box[1] + $box[3]) / 2];

        $wants = static fn (string $layer): bool => \in_array($layer, $layers, true);

        return EcoTerrainMap::fromFeatures(
            $origin,
            roads: $wants(self::ROADS) ? $this->client->features('BDTOPO_V3:troncon_de_route', $box, ['nature', 'acces_vehicule_leger', 'geometrie']) : [],
            vegetation: $wants(self::VEGETATION) ? $this->client->features('BDTOPO_V3:zone_de_vegetation', $box, ['nature', 'geometrie']) : [],
            waterLines: $wants(self::WATER) ? $this->client->features('BDTOPO_V3:troncon_hydrographique', $box, ['geometrie']) : [],
            waterSurfaces: $wants(self::WATER) ? $this->client->features('BDTOPO_V3:surface_hydrographique', $box, ['geometrie']) : [],
            publicForests: $wants(self::PUBLIC_FORESTS) ? $this->client->features('BDTOPO_V3:foret_publique', $box, ['toponyme', 'geometrie']) : [],
            toponyms: $wants(self::PLACES) ? $this->client->features('BDTOPO_V3:toponymie', $box, null, 2000) : [],
        );
    }

    /**
     * South, west, north, east of the points, widened by a margin in metres.
     *
     * @param list<array{float, float}> $points
     *
     * @return array{float, float, float, float}
     */
    public static function boundingBox(array $points, float $marginMeters): array
    {
        if ([] === $points) {
            throw new \InvalidArgumentException('An area needs at least one point.');
        }

        $latitudes = array_column($points, 0);
        $longitudes = array_column($points, 1);
        $south = min($latitudes);
        $north = max($latitudes);

        $latitudeMargin = $marginMeters / 110_574.0;
        $longitudeMargin = $marginMeters / (111_320.0 * cos(deg2rad(($south + $north) / 2)));

        return [
            $south - $latitudeMargin,
            min($longitudes) - $longitudeMargin,
            $north + $latitudeMargin,
            max($longitudes) + $longitudeMargin,
        ];
    }
}
