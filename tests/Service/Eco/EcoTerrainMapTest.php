<?php

declare(strict_types=1);

namespace App\Tests\Service\Eco;

use App\Service\Eco\EcoTerrainMap;
use PHPUnit\Framework\TestCase;

/**
 * The questions e-CO asks of the BD TOPO, on hand-drawn geometries around a point near Limoges.
 *
 * At 45.85° N one degree of latitude is ~110.6 km and one of longitude ~77.6 km, so 0.0001° is
 * about 11 m north-south and 7.8 m east-west - the offsets below are chosen on that scale.
 */
class EcoTerrainMapTest extends TestCase
{
    private const float LAT = 45.85;
    private const float LON = 1.23;

    public function testAFixOnAFootpathIsOnAPathAndOneFiftyMetresOffIsNot(): void
    {
        $map = EcoTerrainMap::fromFeatures([self::LAT, self::LON], roads: [
            $this->line('Sentier', 'Physiquement impossible', [[self::LAT, self::LON - 0.01], [self::LAT, self::LON + 0.01]]),
        ]);

        self::assertEqualsWithDelta(0.0, $map->distanceToPath(self::LAT, self::LON), 0.5);
        // ~5.5 m north of the path: still on it for a phone.
        self::assertNotNull($map->distanceToPath(self::LAT + 0.00005, self::LON, 12.0));
        // ~55 m north: across country.
        self::assertNull($map->distanceToPath(self::LAT + 0.0005, self::LON, 12.0));
        self::assertEqualsWithDelta(55.3, (float) $map->distanceToPath(self::LAT + 0.0005, self::LON), 0.5);
    }

    public function testAFootpathIsNeverARoadForARescueVehicle(): void
    {
        $map = EcoTerrainMap::fromFeatures([self::LAT, self::LON], roads: [
            $this->line('Sentier', 'Physiquement impossible', [[self::LAT, self::LON - 0.01], [self::LAT, self::LON + 0.01]]),
            $this->line('Chemin', 'Libre', [[self::LAT + 0.001, self::LON - 0.01], [self::LAT + 0.001, self::LON + 0.01]]),
            $this->line('Route à 1 chaussée', 'Libre', [[self::LAT + 0.002, self::LON - 0.01], [self::LAT + 0.002, self::LON + 0.01]]),
        ]);

        // The road 0.002° north (~221 m), not the footpath under the flag nor the track at ~110 m.
        self::assertEqualsWithDelta(221.1, (float) $map->distanceToCarRoad(self::LAT, self::LON), 1.0);
    }

    public function testARoadClosedToVehiclesDoesNotCountEither(): void
    {
        $map = EcoTerrainMap::fromFeatures([self::LAT, self::LON], roads: [
            $this->line('Route empierrée', 'Physiquement impossible', [[self::LAT, self::LON - 0.01], [self::LAT, self::LON + 0.01]]),
        ]);

        self::assertNull($map->distanceToCarRoad(self::LAT, self::LON));
    }

    public function testAWoodCountsAndAHedgeDoesNot(): void
    {
        $map = EcoTerrainMap::fromFeatures([self::LAT, self::LON], vegetation: [
            $this->square('Forêt fermée de feuillus', self::LAT, self::LON, 0.001),
            $this->square('Haie', self::LAT + 0.01, self::LON, 0.001),
        ]);

        self::assertTrue($map->isInForest(self::LAT, self::LON));
        self::assertFalse($map->isInForest(self::LAT + 0.01, self::LON));
        self::assertFalse($map->isInForest(self::LAT + 0.005, self::LON));
    }

    public function testAClearingInsideAWoodIsOpenGround(): void
    {
        $outer = $this->ring(self::LAT, self::LON, 0.002);
        $clearing = $this->ring(self::LAT, self::LON, 0.0005);
        $map = EcoTerrainMap::fromFeatures([self::LAT, self::LON], vegetation: [
            ['properties' => ['nature' => 'Bois'], 'geometry' => ['type' => 'Polygon', 'coordinates' => [$outer, $clearing]]],
        ]);

        self::assertFalse($map->isInForest(self::LAT, self::LON));
        self::assertTrue($map->isInForest(self::LAT + 0.001, self::LON));
    }

    public function testTheShoreOfALakeIsWater(): void
    {
        $map = EcoTerrainMap::fromFeatures([self::LAT, self::LON], waterSurfaces: [
            ['properties' => [], 'geometry' => ['type' => 'Polygon', 'coordinates' => [$this->ring(self::LAT + 0.001, self::LON, 0.0005)]]],
        ]);

        // The lake's southern shore is 0.0005° north: ~55 m.
        self::assertEqualsWithDelta(55.3, (float) $map->distanceToWater(self::LAT, self::LON), 1.0);
    }

    public function testAPublicForestIsNamedAndAPlaceIsFoundButNotARiver(): void
    {
        $map = EcoTerrainMap::fromFeatures(
            [self::LAT, self::LON],
            publicForests: [[
                'properties' => ['toponyme' => 'Forêt Domaniale des Essais'],
                'geometry' => ['type' => 'MultiPolygon', 'coordinates' => [[$this->ring(self::LAT, self::LON, 0.002)]]],
            ]],
            toponyms: [
                ['properties' => ['graphie_du_toponyme' => 'ruisseau du test', 'classe_de_l_objet' => "Cours d'eau"], 'geometry' => ['type' => 'Point', 'coordinates' => [self::LON, self::LAT]]],
                ['properties' => ['graphie_du_toponyme' => 'le moulin du bois', 'classe_de_l_objet' => "Zone d'habitation"], 'geometry' => ['type' => 'Point', 'coordinates' => [self::LON + 0.002, self::LAT]]],
            ],
        );

        self::assertSame('Forêt Domaniale des Essais', $map->publicForestAt(self::LAT, self::LON));
        self::assertNull($map->publicForestAt(self::LAT + 0.01, self::LON));
        self::assertSame('Le moulin du bois', $map->nearestPlaceName(self::LAT, self::LON));
    }

    /**
     * @param list<array{float, float}> $points [latitude, longitude]
     *
     * @return array{properties: array<string, mixed>, geometry: array<string, mixed>}
     */
    private function line(string $nature, string $access, array $points): array
    {
        return [
            'properties' => ['nature' => $nature, 'acces_vehicule_leger' => $access],
            'geometry' => ['type' => 'LineString', 'coordinates' => array_map(static fn (array $point): array => [$point[1], $point[0], 300.0], $points)],
        ];
    }

    /** @return array{properties: array<string, mixed>, geometry: array<string, mixed>} */
    private function square(string $nature, float $latitude, float $longitude, float $half): array
    {
        return ['properties' => ['nature' => $nature], 'geometry' => ['type' => 'Polygon', 'coordinates' => [$this->ring($latitude, $longitude, $half)]]];
    }

    /** @return list<array{float, float}> a closed GeoJSON ring, longitude first */
    private function ring(float $latitude, float $longitude, float $half): array
    {
        return [
            [$longitude - $half, $latitude - $half],
            [$longitude + $half, $latitude - $half],
            [$longitude + $half, $latitude + $half],
            [$longitude - $half, $latitude + $half],
            [$longitude - $half, $latitude - $half],
        ];
    }
}
