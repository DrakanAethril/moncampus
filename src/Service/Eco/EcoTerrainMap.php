<?php

declare(strict_types=1);

namespace App\Service\Eco;

/**
 * The ground of one area, as the BD TOPO draws it, reduced to the questions e-CO asks of it: is
 * this point on a path, in a wood, in a public forest; how far is the nearest road a vehicle can
 * use, the nearest water, the nearest named place.
 *
 * Built from the WFS features of App\Service\Ign\IgnGeoplateformeClient::features() by
 * fromFeatures(), and pure from then on - no call leaves it, which is what lets it be tested on
 * hand-written geometries.
 *
 * Distances are worked out on a flat projection centred on the area (a local equirectangular
 * one): at the scale of a parcours - a few kilometres - its error stays well under the metre,
 * far below what a phone's fix is worth.
 *
 * @phpstan-type TerrainPolygon array{box: array{float, float, float, float}, rings: list<list<array{float, float}>>, name: ?string}
 */
final class EcoTerrainMap
{
    /** Side of the grid cells segments are indexed in, in metres. */
    private const float CELL = 50.0;

    private const float METERS_PER_DEGREE_LATITUDE = 110_574.0;
    private const float METERS_PER_DEGREE_LONGITUDE_AT_EQUATOR = 111_320.0;

    /**
     * What a road must be for a rescue vehicle to reach it: a carriageway, or a stone track -
     * never a footpath, a track or steps, and never one the BD TOPO marks as physically closed to
     * light vehicles.
     */
    private const array CAR_ROAD_NATURES = [
        'Route à 1 chaussée',
        'Route à 2 chaussées',
        'Route empierrée',
        'Rond-point',
        'Bretelle',
        'Type autoroutier',
    ];

    /** Vegetation natures that are a wood, as opposed to a hedge, an orchard, a vineyard or heath. */
    private const array FOREST_NATURE_PREFIXES = ['Forêt', 'Bois', 'Peupleraie'];

    private readonly float $metersPerDegreeLongitude;

    /** @var array<string, list<int>> */
    private array $pathGrid = [];

    /** @var array<string, list<int>> */
    private array $carRoadGrid = [];

    /** @var array<string, list<int>> */
    private array $waterGrid = [];

    /** @var array<string, bool> */
    private array $forestMemo = [];

    /**
     * @param list<array{float, float, float, float}> $paths          every road, track and footpath, as projected segments
     * @param list<array{float, float, float, float}> $carRoads       the subset a vehicle can use
     * @param list<array{float, float, float, float}> $water          rivers and the edges of lakes and ponds
     * @param list<TerrainPolygon>                    $forests
     * @param list<TerrainPolygon>                    $publicForests
     * @param list<array{name: string, x: float, y: float}> $places
     */
    private function __construct(
        private readonly float $originLatitude,
        private readonly float $originLongitude,
        private readonly array $paths,
        private readonly array $carRoads,
        private readonly array $water,
        private readonly array $forests,
        private readonly array $publicForests,
        private readonly array $places,
    ) {
        $this->metersPerDegreeLongitude = self::METERS_PER_DEGREE_LONGITUDE_AT_EQUATOR * cos(deg2rad($originLatitude));
        $this->pathGrid = $this->index($paths);
        $this->carRoadGrid = $this->index($carRoads);
        $this->waterGrid = $this->index($water);
    }

    /**
     * @param array{float, float}                                                                      $origin        [latitude, longitude] the projection is centred on
     * @param list<array{properties: array<string, mixed>, geometry: array<string, mixed>}> $roads         BDTOPO_V3:troncon_de_route
     * @param list<array{properties: array<string, mixed>, geometry: array<string, mixed>}> $vegetation    BDTOPO_V3:zone_de_vegetation
     * @param list<array{properties: array<string, mixed>, geometry: array<string, mixed>}> $waterLines    BDTOPO_V3:troncon_hydrographique
     * @param list<array{properties: array<string, mixed>, geometry: array<string, mixed>}> $waterSurfaces BDTOPO_V3:surface_hydrographique
     * @param list<array{properties: array<string, mixed>, geometry: array<string, mixed>}> $publicForests BDTOPO_V3:foret_publique
     * @param list<array{properties: array<string, mixed>, geometry: array<string, mixed>}> $toponyms      BDTOPO_V3:toponymie
     */
    public static function fromFeatures(
        array $origin,
        array $roads = [],
        array $vegetation = [],
        array $waterLines = [],
        array $waterSurfaces = [],
        array $publicForests = [],
        array $toponyms = [],
    ): self {
        $projector = new self($origin[0], $origin[1], [], [], [], [], [], []);

        $paths = [];
        $carRoads = [];
        foreach ($roads as $road) {
            $segments = $projector->segmentsOf($road['geometry']);
            array_push($paths, ...$segments);

            $nature = $road['properties']['nature'] ?? null;
            $access = $road['properties']['acces_vehicule_leger'] ?? null;
            if (\in_array($nature, self::CAR_ROAD_NATURES, true) && 'Physiquement impossible' !== $access) {
                array_push($carRoads, ...$segments);
            }
        }

        $forests = [];
        foreach ($vegetation as $zone) {
            $nature = $zone['properties']['nature'] ?? null;
            if (!\is_string($nature) || !self::isForestNature($nature)) {
                continue;
            }
            array_push($forests, ...$projector->polygonsOf($zone['geometry'], null));
        }

        $water = [];
        foreach ($waterLines as $line) {
            array_push($water, ...$projector->segmentsOf($line['geometry']));
        }
        foreach ($waterSurfaces as $surface) {
            // A lake is dangerous at its shore: its outline goes in with the rivers.
            foreach ($projector->polygonsOf($surface['geometry'], null) as $polygon) {
                foreach ($polygon['rings'] as $ring) {
                    array_push($water, ...self::ringSegments($ring));
                }
            }
        }

        $public = [];
        foreach ($publicForests as $forest) {
            $name = $forest['properties']['toponyme'] ?? null;
            array_push($public, ...$projector->polygonsOf($forest['geometry'], \is_string($name) && '' !== $name ? $name : null));
        }

        $places = [];
        foreach ($toponyms as $toponym) {
            $name = $toponym['properties']['graphie_du_toponyme'] ?? null;
            $class = $toponym['properties']['classe_de_l_objet'] ?? null;
            $point = $projector->pointOf($toponym['geometry']);
            // A river's name placed at one point of its course says nothing about where one stands.
            if (!\is_string($name) || '' === $name || null === $point || "Cours d'eau" === $class) {
                continue;
            }
            $places[] = ['name' => self::capitalised($name), 'x' => $point[0], 'y' => $point[1]];
        }

        return new self($origin[0], $origin[1], $paths, $carRoads, $water, $forests, $public, $places);
    }

    public static function isForestNature(string $nature): bool
    {
        foreach (self::FOREST_NATURE_PREFIXES as $prefix) {
            if (str_starts_with($nature, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Distance to the nearest road, track or footpath, or null when none lies within $radius. */
    public function distanceToPath(float $latitude, float $longitude, float $radius = 2000.0): ?float
    {
        return $this->nearest($this->paths, $this->pathGrid, $this->project($latitude, $longitude), $radius);
    }

    /** Distance to the nearest road a vehicle can use, or null when none lies within $radius. */
    public function distanceToCarRoad(float $latitude, float $longitude, float $radius = 3000.0): ?float
    {
        return $this->nearest($this->carRoads, $this->carRoadGrid, $this->project($latitude, $longitude), $radius);
    }

    /** Distance to the nearest river or shore, or null when none lies within $radius. */
    public function distanceToWater(float $latitude, float $longitude, float $radius = 2000.0): ?float
    {
        return $this->nearest($this->water, $this->waterGrid, $this->project($latitude, $longitude), $radius);
    }

    public function isInForest(float $latitude, float $longitude): bool
    {
        $point = $this->project($latitude, $longitude);
        // A race logs a fix every few seconds over the same few hectares: points five metres apart
        // get the same answer, and the polygons - some carry thousands of vertices - are walked once.
        $key = \sprintf('%d:%d', (int) floor($point[0] / 5.0), (int) floor($point[1] / 5.0));

        return $this->forestMemo[$key] ??= null !== $this->containing($this->forests, $point);
    }

    /** The public forest the point stands in, by name, or null. */
    public function publicForestAt(float $latitude, float $longitude): ?string
    {
        $polygon = $this->containing($this->publicForests, $this->project($latitude, $longitude));

        return null !== $polygon ? ($polygon['name'] ?? '') : null;
    }

    /** The nearest named place within $radius, or null. */
    public function nearestPlaceName(float $latitude, float $longitude, float $radius = 1500.0): ?string
    {
        $point = $this->project($latitude, $longitude);
        $best = null;
        $bestDistance = $radius;

        foreach ($this->places as $place) {
            $distance = hypot($place['x'] - $point[0], $place['y'] - $point[1]);
            if ($distance <= $bestDistance) {
                $best = $place['name'];
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * @param list<array{float, float, float, float}> $segments
     * @param array<string, list<int>>                $grid
     * @param array{float, float}                     $point
     */
    private function nearest(array $segments, array $grid, array $point, float $radius): ?float
    {
        $best = null;
        $cellX = (int) floor($point[0] / self::CELL);
        $cellY = (int) floor($point[1] / self::CELL);
        $maxRing = (int) ceil($radius / self::CELL);

        for ($ring = 0; $ring <= $maxRing; ++$ring) {
            $seen = [];
            for ($dx = -$ring; $dx <= $ring; ++$dx) {
                for ($dy = -$ring; $dy <= $ring; ++$dy) {
                    // Only the ring's own border: the inside was searched on the previous turns.
                    if (max(abs($dx), abs($dy)) !== $ring) {
                        continue;
                    }
                    foreach ($grid[($cellX + $dx).':'.($cellY + $dy)] ?? [] as $index) {
                        if (isset($seen[$index])) {
                            continue;
                        }
                        $seen[$index] = true;
                        $distance = self::pointToSegment($point, $segments[$index]);
                        if (null === $best || $distance < $best) {
                            $best = $distance;
                        }
                    }
                }
            }

            // Anything in a further ring is at least $ring cells away.
            if (null !== $best && $best <= $ring * self::CELL) {
                break;
            }
        }

        return null !== $best && $best <= $radius ? $best : null;
    }

    /**
     * @param list<TerrainPolygon> $polygons
     * @param array{float, float}  $point
     *
     * @return TerrainPolygon|null
     */
    private function containing(array $polygons, array $point): ?array
    {
        foreach ($polygons as $polygon) {
            [$minX, $minY, $maxX, $maxY] = $polygon['box'];
            if ($point[0] < $minX || $point[0] > $maxX || $point[1] < $minY || $point[1] > $maxY) {
                continue;
            }

            // Even-odd over every ring at once: a hole - a clearing inside the wood - flips the
            // answer back, which is exactly what it means on the ground.
            $inside = false;
            foreach ($polygon['rings'] as $ring) {
                $count = \count($ring);
                for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
                    [$xi, $yi] = $ring[$i];
                    [$xj, $yj] = $ring[$j];
                    if (($yi > $point[1]) !== ($yj > $point[1])
                        && $point[0] < ($xj - $xi) * ($point[1] - $yi) / ($yj - $yi) + $xi) {
                        $inside = !$inside;
                    }
                }
            }

            if ($inside) {
                return $polygon;
            }
        }

        return null;
    }

    /**
     * @param list<array{float, float, float, float}> $segments
     *
     * @return array<string, list<int>>
     */
    private function index(array $segments): array
    {
        $grid = [];
        foreach ($segments as $index => [$x1, $y1, $x2, $y2]) {
            for ($cx = (int) floor(min($x1, $x2) / self::CELL); $cx <= (int) floor(max($x1, $x2) / self::CELL); ++$cx) {
                for ($cy = (int) floor(min($y1, $y2) / self::CELL); $cy <= (int) floor(max($y1, $y2) / self::CELL); ++$cy) {
                    $grid[$cx.':'.$cy][] = $index;
                }
            }
        }

        return $grid;
    }

    /**
     * @param array{float, float}               $point
     * @param array{float, float, float, float} $segment
     */
    private static function pointToSegment(array $point, array $segment): float
    {
        [$x1, $y1, $x2, $y2] = $segment;
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        $lengthSquared = $dx * $dx + $dy * $dy;
        $t = $lengthSquared > 0.0 ? max(0.0, min(1.0, (($point[0] - $x1) * $dx + ($point[1] - $y1) * $dy) / $lengthSquared)) : 0.0;

        return hypot($point[0] - ($x1 + $t * $dx), $point[1] - ($y1 + $t * $dy));
    }

    /** @return array{float, float} metres east and north of the origin */
    private function project(float $latitude, float $longitude): array
    {
        return [
            ($longitude - $this->originLongitude) * $this->metersPerDegreeLongitude,
            ($latitude - $this->originLatitude) * self::METERS_PER_DEGREE_LATITUDE,
        ];
    }

    /**
     * A GeoJSON position - longitude first - projected, or null for anything that is not one.
     *
     * @return array{float, float}|null
     */
    private function positionOf(mixed $position): ?array
    {
        if (!\is_array($position) || !is_numeric($position[0] ?? null) || !is_numeric($position[1] ?? null)) {
            return null;
        }

        return $this->project((float) $position[1], (float) $position[0]);
    }

    /**
     * @param array<string, mixed> $geometry
     *
     * @return array{float, float}|null
     */
    private function pointOf(array $geometry): ?array
    {
        $coordinates = $geometry['coordinates'] ?? null;

        return match ($geometry['type'] ?? null) {
            'Point' => $this->positionOf($coordinates),
            'MultiPoint' => \is_array($coordinates) ? $this->positionOf($coordinates[0] ?? null) : null,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $geometry
     *
     * @return list<array{float, float, float, float}>
     */
    private function segmentsOf(array $geometry): array
    {
        $coordinates = $geometry['coordinates'] ?? null;
        if (!\is_array($coordinates)) {
            return [];
        }

        $lines = match ($geometry['type'] ?? null) {
            'LineString' => [$coordinates],
            'MultiLineString' => $coordinates,
            default => [],
        };

        $segments = [];
        foreach ($lines as $line) {
            if (!\is_array($line)) {
                continue;
            }
            $previous = null;
            foreach ($line as $position) {
                $point = $this->positionOf($position);
                if (null === $point) {
                    continue;
                }
                if (null !== $previous) {
                    $segments[] = [$previous[0], $previous[1], $point[0], $point[1]];
                }
                $previous = $point;
            }
        }

        return $segments;
    }

    /**
     * @param array<string, mixed> $geometry
     *
     * @return list<TerrainPolygon>
     */
    private function polygonsOf(array $geometry, ?string $name): array
    {
        $coordinates = $geometry['coordinates'] ?? null;
        if (!\is_array($coordinates)) {
            return [];
        }

        $polygons = match ($geometry['type'] ?? null) {
            'Polygon' => [$coordinates],
            'MultiPolygon' => $coordinates,
            default => [],
        };

        $result = [];
        foreach ($polygons as $polygon) {
            if (!\is_array($polygon)) {
                continue;
            }

            $rings = [];
            $box = [\INF, \INF, -\INF, -\INF];
            foreach ($polygon as $ringCoordinates) {
                if (!\is_array($ringCoordinates)) {
                    continue;
                }
                $ring = [];
                foreach ($ringCoordinates as $position) {
                    $point = $this->positionOf($position);
                    if (null === $point) {
                        continue;
                    }
                    $ring[] = $point;
                    $box = [min($box[0], $point[0]), min($box[1], $point[1]), max($box[2], $point[0]), max($box[3], $point[1])];
                }
                if (\count($ring) >= 3) {
                    $rings[] = $ring;
                }
            }

            if ([] !== $rings) {
                $result[] = ['box' => $box, 'rings' => $rings, 'name' => $name];
            }
        }

        return $result;
    }

    /**
     * @param list<array{float, float}> $ring
     *
     * @return list<array{float, float, float, float}>
     */
    private static function ringSegments(array $ring): array
    {
        $segments = [];
        for ($i = 1, $count = \count($ring); $i < $count; ++$i) {
            $segments[] = [$ring[$i - 1][0], $ring[$i - 1][1], $ring[$i][0], $ring[$i][1]];
        }

        return $segments;
    }

    /** The BD TOPO writes its toponyms in lower case: « le moulin de mas blanc ». */
    private static function capitalised(string $name): string
    {
        return mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1);
    }
}
