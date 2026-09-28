<?php

declare(strict_types=1);

namespace App\Tests\Service\Ign;

use App\Service\Ign\IgnGeoplateformeClient;
use App\Service\Ign\IgnUnavailableException;
use App\Tests\Double\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The Géoplateforme as this client reads it, on answers shaped like the ones measured on
 * 2026-09-28: points go out latitude/longitude swapped to its longitude-first order, its no-data
 * sentinel comes back as null, and an answer of another shape is refused out loud.
 */
class IgnGeoplateformeClientTest extends TestCase
{
    public function testAltitudesGoOutLongitudeFirstAndTheNoDataSentinelComesBackNull(): void
    {
        $sent = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = ['method' => $method, 'url' => $url, 'body' => json_decode((string) $options['body'], true)];

            return new MockResponse((string) json_encode(['elevations' => [265.34, -99999]]));
        }, 'https://data.geopf.fr');

        $altitudes = (new IgnGeoplateformeClient($http, new RecordingLogger()))->groundAltitudes([[45.8336, 1.2611], [48.0, -6.0]]);

        self::assertSame([265.34, null], $altitudes);
        self::assertSame('POST', $sent['method']);
        self::assertStringEndsWith('/altimetrie/1.0/calcul/alti/rest/elevation.json', $sent['url']);
        $body = $sent['body'];
        self::assertIsArray($body);
        self::assertSame('1.261100|-6.000000', $body['lon'] ?? null);
        self::assertSame('45.833600|48.000000', $body['lat'] ?? null);
        self::assertSame('ign_rge_alti_wld', $body['resource'] ?? null);
    }

    public function testTheLidarReadingTellsTheGroundFromTheCanopyByTitle(): void
    {
        $measure = static fn (string $model, float $z): array => [
            'z' => $z,
            'source_name' => 'LIDAR HD IGN',
            'title' => \sprintf('IGN - LIDAR HD - %s - France Métropolitaine (dont Corse)- avec interpolation', $model),
        ];
        $http = new MockHttpClient(new MockResponse((string) json_encode(['elevations' => [[
            'lon' => 1.24, 'lat' => 45.82, 'z' => 275.19,
            'measures' => [$measure('MNT', 275.19), $measure('MNS', 293.4), $measure('MNH', 18.2)],
        ]]])), 'https://data.geopf.fr');

        $readings = (new IgnGeoplateformeClient($http, new RecordingLogger()))->lidarReadings([[45.82, 1.24]]);

        self::assertSame([['ground' => 275.19, 'canopy' => 18.2]], $readings);
    }

    public function testAnAnswerWithTheWrongNumberOfPointsIsRefusedAndReported(): void
    {
        $logger = new RecordingLogger();
        $http = new MockHttpClient(new MockResponse((string) json_encode(['elevations' => [265.34]])), 'https://data.geopf.fr');

        try {
            (new IgnGeoplateformeClient($http, $logger))->groundAltitudes([[45.8, 1.2], [45.9, 1.3]]);
            self::fail('A short answer must not be read as the altitudes of the first points.');
        } catch (IgnUnavailableException) {
        }

        $errors = array_filter($logger->records, static fn (array $record): bool => 'error' === $record['level'] && str_contains($record['message'], 'unrecognised answer'));
        self::assertCount(1, $errors);
    }

    public function testNoRouteIsNullButAnOutageThrows(): void
    {
        $noRoute = new MockHttpClient(new MockResponse('{"error":"no path"}', ['http_code' => 400]), 'https://data.geopf.fr');
        self::assertNull((new IgnGeoplateformeClient($noRoute, new RecordingLogger()))->pedestrianRoute([45.8, 1.2], [45.9, 1.3]));

        $throttled = new MockHttpClient(new MockResponse('', ['http_code' => 429]), 'https://data.geopf.fr');
        $this->expectException(IgnUnavailableException::class);
        (new IgnGeoplateformeClient($throttled, new RecordingLogger()))->pedestrianRoute([45.8, 1.2], [45.9, 1.3]);
    }

    public function testARouteComesBackInLatitudeLongitudeOrder(): void
    {
        $http = new MockHttpClient(new MockResponse((string) json_encode([
            'distance' => 948.295,
            'geometry' => ['type' => 'LineString', 'coordinates' => [[1.253, 45.828], [1.26, 45.833]]],
        ])), 'https://data.geopf.fr');

        $route = (new IgnGeoplateformeClient($http, new RecordingLogger()))->pedestrianRoute([45.828, 1.253], [45.833, 1.26]);

        self::assertSame(['meters' => 948.295, 'points' => [[45.828, 1.253], [45.833, 1.26]]], $route);
    }

    public function testTheCommuneIsTheFirstPoiOfCategoryCommune(): void
    {
        $http = new MockHttpClient(new MockResponse((string) json_encode(['features' => [
            ['properties' => ['toponym' => 'CU Limoges Métropole', 'category' => ['administratif', 'epci']]],
            ['properties' => ['toponym' => 'Couzeix', 'category' => ['administratif', 'commune']]],
        ]])), 'https://data.geopf.fr');

        self::assertSame('Couzeix', (new IgnGeoplateformeClient($http, new RecordingLogger()))->communeAt(45.855, 1.235));
    }
}
