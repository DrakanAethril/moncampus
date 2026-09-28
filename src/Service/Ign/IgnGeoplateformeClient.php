<?php

declare(strict_types=1);

namespace App\Service\Ign;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The IGN's Géoplateforme (data.geopf.fr), as e-CO reads it: terrain altitudes, LiDAR vegetation
 * heights, a pedestrian route between two points, the BD TOPO features of an area, and the commune
 * a point stands in. Open services - no key, Licence Ouverte (Etalab 2.0), the IGN to be credited
 * wherever what they gave is shown.
 *
 * **Each service has its own rate limit, per IP**, and exceeding one gets a 429 and five seconds
 * of silence on that service alone: 5 requests/s for altimetry, 10 for routing, 30 for WFS, 50 for
 * geocoding. The client spaces its own calls to stay under them - app:eco:read-terrain chains
 * several dozen in a pass. The pause is kept on the instance, which in worker mode outlives the
 * request: that is harmless, a timestamp that is too old only means no pause.
 *
 * Points are `[latitude, longitude]` pairs throughout, like the rest of e-CO; the Géoplateforme
 * speaks longitude first, and the swap happens here and nowhere else.
 *
 * Nothing it throws but IgnUnavailableException. An answer whose shape is not the documented one
 * is logged at error level - it means the service changed, which somebody has to hear about - and
 * refused rather than guessed at.
 */
class IgnGeoplateformeClient
{
    /** The altimetry service's own ceiling on the points of one request. */
    public const int ALTIMETRY_BATCH = 5000;

    /** What the altimetry service answers where its data has a hole (outside France, the sea). */
    private const float NO_DATA = -99999.0;

    /** Minimum seconds between two calls to one service - its published limit, with a margin. */
    private const array MIN_INTERVAL = [
        'altimetry' => 0.22,
        'routing' => 0.11,
        'wfs' => 0.04,
        'geocoding' => 0.025,
    ];

    /** @var array<string, float> */
    private array $lastCallAt = [];

    public function __construct(
        private readonly HttpClientInterface $ignHttpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The altitude of the terrain under each point, from the RGE ALTI (1 m to 5 m, the whole of
     * France), in the order given. Null where the IGN has no data.
     *
     * @param list<array{float, float}> $points
     *
     * @return list<?float>
     */
    public function groundAltitudes(array $points, ?float $maxDuration = null): array
    {
        $altitudes = [];

        foreach (array_chunk($points, self::ALTIMETRY_BATCH) as $batch) {
            $answer = $this->elevations($batch, 'ign_rge_alti_wld', false, $maxDuration);

            foreach ($answer as $elevation) {
                $altitudes[] = $this->zOf($elevation);
            }
        }

        return $altitudes;
    }

    /**
     * The LiDAR HD reading under each point: the terrain altitude, and the height of what stands
     * on it (MNH - canopy, hedges, buildings). Null where LiDAR HD has not covered the area yet;
     * its coverage is still growing.
     *
     * @param list<array{float, float}> $points
     *
     * @return list<array{ground: ?float, canopy: ?float}>
     */
    public function lidarReadings(array $points, ?float $maxDuration = null): array
    {
        $readings = [];

        foreach (array_chunk($points, self::ALTIMETRY_BATCH) as $batch) {
            foreach ($this->elevations($batch, 'ign_lidar_hd_mnx_mono_wld', true, $maxDuration) as $elevation) {
                $ground = null;
                $canopy = null;
                $measures = \is_array($elevation) ? ($elevation['measures'] ?? null) : null;

                if (!\is_array($measures)) {
                    $this->refuse('altimetry', 'LiDAR reading without measures');
                }

                foreach ($measures as $measure) {
                    $title = \is_array($measure) && \is_string($measure['title'] ?? null) ? $measure['title'] : '';
                    // The three models are told apart by their title only: "IGN - LIDAR HD - MNH - …".
                    if (str_contains($title, '- MNT -')) {
                        $ground = $this->zOf($measure);
                    } elseif (str_contains($title, '- MNH -')) {
                        $canopy = $this->zOf($measure);
                    }
                }

                $readings[] = ['ground' => $ground, 'canopy' => $canopy];
            }
        }

        return $readings;
    }

    /**
     * The shortest walk between two points over the BD TOPO network - roads, tracks and footpaths -
     * or null when the router finds no way at all.
     *
     * @param array{float, float} $from
     * @param array{float, float} $to
     *
     * @return array{meters: float, points: list<array{float, float}>}|null
     */
    public function pedestrianRoute(array $from, array $to): ?array
    {
        $data = $this->getJson('routing', '/navigation/itineraire', [
            'resource' => 'bdtopo-valhalla',
            'profile' => 'pedestrian',
            'optimization' => 'shortest',
            'start' => $this->lonLat($from),
            'end' => $this->lonLat($to),
            'geometryFormat' => 'geojson',
            'getSteps' => 'false',
            'distanceUnit' => 'meter',
        ], nullOnClientError: true);

        if (null === $data) {
            return null;
        }

        $distance = $data['distance'] ?? null;
        $geometry = $data['geometry'] ?? null;
        $coordinates = \is_array($geometry) ? ($geometry['coordinates'] ?? null) : null;

        if (!is_numeric($distance) || !\is_array($coordinates)) {
            $this->refuse('routing', 'route without distance or geometry');
        }

        return ['meters' => (float) $distance, 'points' => $this->latLngList($coordinates)];
    }

    /**
     * The features of one WFS layer inside a box, paged until the layer has nothing more or the
     * limit is reached. Geometries come back in WGS 84, longitude first, as GeoJSON has them.
     *
     * @param array{float, float, float, float} $box        south, west, north, east
     * @param list<string>|null                 $properties the attributes to fetch, geometry included; null for all
     *
     * @return list<array{properties: array<string, mixed>, geometry: array<string, mixed>}>
     */
    public function features(string $typeName, array $box, ?array $properties = null, int $limit = 10000): array
    {
        $pageSize = 1000;
        $features = [];

        for ($start = 0; $start < $limit; $start += $pageSize) {
            $query = [
                'SERVICE' => 'WFS',
                'VERSION' => '2.0.0',
                'REQUEST' => 'GetFeature',
                'TYPENAMES' => $typeName,
                'OUTPUTFORMAT' => 'application/json',
                'SRSNAME' => 'EPSG:4326',
                'BBOX' => \sprintf('%.6F,%.6F,%.6F,%.6F,urn:ogc:def:crs:EPSG::4326', ...$box),
                'COUNT' => $pageSize,
                'STARTINDEX' => $start,
            ];
            if (null !== $properties) {
                $query['PROPERTYNAME'] = implode(',', $properties);
            }

            $data = $this->getJson('wfs', '/wfs/ows', $query);
            $page = $data['features'] ?? null;

            if (!\is_array($page)) {
                $this->refuse('wfs', 'feature collection without features');
            }

            foreach ($page as $feature) {
                $geometry = \is_array($feature) ? ($feature['geometry'] ?? null) : null;
                if (!\is_array($geometry)) {
                    continue;
                }
                $featureProperties = $feature['properties'] ?? null;
                $features[] = [
                    'properties' => \is_array($featureProperties) ? $featureProperties : [],
                    'geometry' => $geometry,
                ];
            }

            if (\count($page) < $pageSize) {
                break;
            }
        }

        return $features;
    }

    /** The commune a point stands in, by name - null outside France or when the index has none. */
    public function communeAt(float $latitude, float $longitude): ?string
    {
        $data = $this->getJson('geocoding', '/geocodage/reverse', [
            'lon' => \sprintf('%.6F', $longitude),
            'lat' => \sprintf('%.6F', $latitude),
            'index' => 'poi',
            'limit' => 10,
        ]);

        $features = $data['features'] ?? null;
        if (!\is_array($features)) {
            $this->refuse('geocoding', 'reverse answer without features');
        }

        foreach ($features as $feature) {
            $properties = \is_array($feature) ? ($feature['properties'] ?? null) : null;
            if (!\is_array($properties)) {
                continue;
            }
            $category = $properties['category'] ?? null;
            $toponym = $properties['toponym'] ?? null;

            if (\is_array($category) && \in_array('commune', $category, true) && \is_string($toponym) && '' !== $toponym) {
                return $toponym;
            }
        }

        return null;
    }

    /**
     * @param list<array{float, float}> $points
     *
     * @return list<mixed>
     */
    private function elevations(array $points, string $resource, bool $measures, ?float $maxDuration): array
    {
        if ([] === $points) {
            return [];
        }

        // Sent as a JSON body: 5 000 points do not fit in a query string.
        $data = $this->request('altimetry', 'POST', '/altimetrie/1.0/calcul/alti/rest/elevation.json', [
            'json' => [
                'lon' => implode('|', array_map(static fn (array $point): string => \sprintf('%.6F', $point[1]), $points)),
                'lat' => implode('|', array_map(static fn (array $point): string => \sprintf('%.6F', $point[0]), $points)),
                'resource' => $resource,
                'zonly' => $measures ? 'false' : 'true',
                'measures' => $measures ? 'true' : 'false',
            ],
        ] + (null !== $maxDuration ? ['max_duration' => $maxDuration] : []));

        $elevations = $data['elevations'] ?? null;
        if (!\is_array($elevations) || \count($elevations) !== \count($points)) {
            $this->refuse('altimetry', 'elevation count does not match the points sent');
        }

        return array_values($elevations);
    }

    /** A numeric altitude, or null for the no-data sentinel and anything that is not a number. */
    private function zOf(mixed $elevation): ?float
    {
        $z = \is_array($elevation) ? ($elevation['z'] ?? null) : $elevation;

        if (!is_numeric($z) || (float) $z <= self::NO_DATA + 1.0) {
            return null;
        }

        return (float) $z;
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array<string, mixed>|null
     */
    private function getJson(string $service, string $path, array $query, bool $nullOnClientError = false): ?array
    {
        return $this->request($service, 'GET', $path, ['query' => $query], $nullOnClientError);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return ($nullOnClientError is true ? array<string, mixed>|null : array<string, mixed>)
     */
    private function request(string $service, string $method, string $path, array $options, bool $nullOnClientError = false): ?array
    {
        $this->pace($service);

        try {
            $response = $this->ignHttpClient->request($method, $path, $options);
            $status = $response->getStatusCode();

            // A route between two points the network cannot join is a 4xx, not an outage.
            if ($nullOnClientError && $status >= 400 && $status < 500 && 429 !== $status) {
                return null;
            }

            if (200 !== $status) {
                throw new IgnUnavailableException(\sprintf('Géoplateforme %s answered HTTP %d.', $service, $status));
            }

            $data = $response->toArray(false);
        } catch (ExceptionInterface $exception) {
            throw new IgnUnavailableException(\sprintf('Géoplateforme %s unreachable: %s', $service, $exception->getMessage()), 0, $exception);
        }

        return $data;
    }

    private function pace(string $service): void
    {
        $interval = self::MIN_INTERVAL[$service] ?? 0.0;
        $elapsed = microtime(true) - ($this->lastCallAt[$service] ?? 0.0);

        if ($elapsed < $interval) {
            usleep((int) (($interval - $elapsed) * 1_000_000));
        }

        $this->lastCallAt[$service] = microtime(true);
    }

    /** @param array{float, float} $point */
    private function lonLat(array $point): string
    {
        return \sprintf('%.6F,%.6F', $point[1], $point[0]);
    }

    /**
     * GeoJSON positions, longitude first, back to e-CO's [latitude, longitude].
     *
     * @param array<mixed> $coordinates
     *
     * @return list<array{float, float}>
     */
    private function latLngList(array $coordinates): array
    {
        $points = [];
        foreach ($coordinates as $position) {
            if (\is_array($position) && is_numeric($position[0] ?? null) && is_numeric($position[1] ?? null)) {
                $points[] = [(float) $position[1], (float) $position[0]];
            }
        }

        return $points;
    }

    private function refuse(string $service, string $what): never
    {
        $this->logger->error('Géoplateforme {service}: unrecognised answer ({what}).', ['service' => $service, 'what' => $what]);

        throw new IgnUnavailableException(\sprintf('Géoplateforme %s: unrecognised answer (%s).', $service, $what));
    }
}
