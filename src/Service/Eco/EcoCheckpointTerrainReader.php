<?php

declare(strict_types=1);

namespace App\Service\Eco;

use App\Entity\EcoCheckpoint;
use App\Service\Ign\IgnGeoplateformeClient;
use App\Service\Ign\IgnUnavailableException;
use Psr\Log\LoggerInterface;

/**
 * Reads, from the IGN, the ground under one flag: its altitude and the vegetation around it.
 *
 * The vegetation is read on a ring rather than at the flag alone. The LiDAR's canopy model has a
 * 50 cm step: a flag planted at the foot of an oak can sit on a pixel of bare ground, and one in a
 * gap between two trees reads zero - while the phone looking for it is under the leaves all the
 * same. The median of the flag and eight points ten metres around it is the canopy a runner stands
 * under when they get there.
 *
 * Never throws: a Géoplateforme that does not answer leaves the flag unread, and the next reader -
 * app:eco:read-terrain - tries again.
 */
class EcoCheckpointTerrainReader
{
    private const float RING_METERS = 10.0;

    public function __construct(
        private readonly IgnGeoplateformeClient $client,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param float|null $maxDuration seconds the IGN is given; the mobile app's locate request
     *                                passes a short one, a person is waiting on it
     *
     * @return bool whether the flag now carries a reading
     */
    public function read(EcoCheckpoint $checkpoint, ?float $maxDuration = null): bool
    {
        if (!$checkpoint->isLocated()) {
            return false;
        }

        $latitude = (float) $checkpoint->getLatitude();
        $longitude = (float) $checkpoint->getLongitude();

        try {
            $readings = $this->client->lidarReadings($this->ring($latitude, $longitude), $maxDuration);
            $ground = $readings[0]['ground'] ?? null;

            // Outside LiDAR HD's coverage (still growing), the RGE ALTI has every square metre of
            // France - but no vegetation.
            if (null === $ground) {
                $ground = $this->client->groundAltitudes([[$latitude, $longitude]], $maxDuration)[0] ?? null;
            }
        } catch (IgnUnavailableException $exception) {
            $this->logger->warning('IGN terrain reading of e-CO checkpoint {id} failed: {message}', [
                'id' => $checkpoint->getId(),
                'message' => $exception->getMessage(),
            ]);

            return false;
        }

        $canopies = array_values(array_filter(
            array_column($readings, 'canopy'),
            static fn (?float $height): bool => null !== $height,
        ));

        $checkpoint->recordTerrain(
            null !== $ground ? round($ground, 1) : null,
            [] !== $canopies ? round(self::median($canopies), 1) : null,
            new \DateTimeImmutable(),
        );

        return true;
    }

    /**
     * The flag first, then eight points around it.
     *
     * @return list<array{float, float}>
     */
    private function ring(float $latitude, float $longitude): array
    {
        $points = [[$latitude, $longitude]];
        $latitudeStep = self::RING_METERS / 110_574.0;
        $longitudeStep = self::RING_METERS / (111_320.0 * cos(deg2rad($latitude)));

        for ($i = 0; $i < 8; ++$i) {
            $angle = $i * M_PI / 4;
            $points[] = [$latitude + $latitudeStep * sin($angle), $longitude + $longitudeStep * cos($angle)];
        }

        return $points;
    }

    /** @param non-empty-list<float> $values */
    private static function median(array $values): float
    {
        sort($values);
        $count = \count($values);
        $middle = intdiv($count, 2);

        return 0 === $count % 2 ? ($values[$middle - 1] + $values[$middle]) / 2 : $values[$middle];
    }
}
