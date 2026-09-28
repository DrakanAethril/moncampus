<?php

declare(strict_types=1);

namespace App\Service\Eco;

use App\Entity\EcoCheckpointScan;
use App\Entity\EcoCourse;
use App\Entity\EcoParcours;
use App\Entity\EcoPositionPing;
use App\Enum\EcoCourseStatus;
use App\Enum\EcoScanResult;
use App\Repository\EcoCourseRepository;
use App\Repository\EcoPositionPingRepository;
use App\Service\Ign\IgnGeoplateformeClient;
use App\Service\Ign\IgnUnavailableException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * After a race, asks the IGN about every GPS fix of it: the terrain altitude under it, whether it
 * sits on a path of the BD TOPO, whether it sits in a wood. What the results screens then build on
 * it - an elevation gain that no longer depends on the phone, the share of the race run off the
 * paths, the speed in the woods - reads these three stored answers and never calls the IGN itself.
 *
 * One course per pass, oldest closed first, which also works through the races closed before this
 * existed. A fix is "on a path" within PATH_METERS of one: a phone's fix is worth a few metres, a
 * path of the BD TOPO a few more, and a runner on a track must not read as off it.
 *
 * It also completes the parcours' routes with the pairs of flags runners actually ran (free order
 * and score courses run pairs the parcours' own order does not have), so their detour can be read
 * against the shortest walk as well as against the straight line.
 */
class EcoPingTerrainResolver
{
    public const float PATH_METERS = 12.0;

    /** Fixes resolved in one pass - a whole class's hour of racing is some 20 000. */
    private const int PINGS_PER_PASS = 25000;

    /** Routes asked in one pass: the router allows ten a second, the worker has other tasks. */
    private const int ROUTES_PER_PASS = 40;

    public function __construct(
        private readonly EcoPositionPingRepository $pingRepository,
        private readonly EcoCourseRepository $courseRepository,
        private readonly EcoTerrainMapLoader $mapLoader,
        private readonly IgnGeoplateformeClient $client,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Resolves the fixes of the next course waiting for it, and flushes.
     *
     * @return array{course: EcoCourse, pings: int}|null null when no closed course is waiting
     *
     * @throws IgnUnavailableException the course stays waiting for the next pass
     */
    public function resolveNextCourse(): ?array
    {
        $courseId = $this->pingRepository->findCourseIdWithUnresolvedTerrain();
        $course = null !== $courseId ? $this->courseRepository->find($courseId) : null;

        if (null === $course) {
            return null;
        }

        $pings = $this->pingRepository->findUnresolvedTerrainForCourse($course, self::PINGS_PER_PASS);
        $points = array_map(
            static fn (EcoPositionPing $ping): array => [(float) $ping->getLatitude(), (float) $ping->getLongitude()],
            $pings,
        );

        if ([] !== $points) {
            $map = $this->mapLoader->load($points, [EcoTerrainMapLoader::ROADS, EcoTerrainMapLoader::VEGETATION], 60.0);
            $altitudes = $this->client->groundAltitudes($points);

            foreach ($pings as $index => $ping) {
                [$latitude, $longitude] = $points[$index];
                $altitude = $altitudes[$index] ?? null;
                $ping->resolveTerrain(
                    null !== $altitude ? round($altitude, 1) : null,
                    null !== $map->distanceToPath($latitude, $longitude, self::PATH_METERS),
                    $map->isInForest($latitude, $longitude),
                );
            }
        }

        $parcours = $course->getParcours();
        // A race on a parcours never analysed asks for its analysis: its legs will be read against
        // the paths as well.
        if (!$parcours->hasCurrentTerrainAnalysis()) {
            $parcours->requestTerrainAnalysis(new \DateTimeImmutable());
        }

        $this->entityManager->flush();

        return ['course' => $course, 'pings' => \count($pings)];
    }

    /**
     * Routes the pairs of flags runners ran on this parcours that its analysis does not have yet,
     * and flushes. Returns how many were asked.
     */
    public function completeRoutes(EcoParcours $parcours): int
    {
        if (!$parcours->hasCurrentTerrainAnalysis()) {
            return 0;
        }

        $checkpoints = [];
        foreach ($parcours->getCheckpoints() as $checkpoint) {
            if ($checkpoint->isLocated()) {
                $checkpoints[(int) $checkpoint->getId()] = [(float) $checkpoint->getLatitude(), (float) $checkpoint->getLongitude()];
            }
        }

        $missing = [];
        foreach ($parcours->getCourses() as $course) {
            if (EcoCourseStatus::Closed !== $course->getStatus()) {
                continue;
            }
            foreach ($this->runPairs($course) as [$fromId, $toId]) {
                $key = EcoParcoursTerrainAnalyzer::pairKey($fromId, $toId);
                if (!isset($checkpoints[$fromId], $checkpoints[$toId]) || isset($missing[$key]) || $parcours->hasTerrainRoute($key)) {
                    continue;
                }
                $missing[$key] = [$fromId, $toId];
            }
        }

        $routes = [];
        foreach (\array_slice($missing, 0, self::ROUTES_PER_PASS, true) as $key => [$fromId, $toId]) {
            try {
                $route = $this->client->pedestrianRoute($checkpoints[$fromId], $checkpoints[$toId]);
            } catch (IgnUnavailableException) {
                // Left out rather than recorded as "no route": the next pass asks again.
                continue;
            }
            $routes[$key] = null !== $route ? round($route['meters']) : null;
        }

        if ([] !== $routes) {
            $parcours->addTerrainRoutes($routes);
            $this->entityManager->flush();
        }

        return \count($routes);
    }

    /**
     * The consecutive pairs of flags each runner validated, in the order they did - the legs of
     * EcoPerformanceAnalyzer, reduced to their two ids.
     *
     * @return list<array{int, int}>
     */
    private function runPairs(EcoCourse $course): array
    {
        $pairs = [];

        foreach ($course->getRunners() as $runner) {
            $scans = array_filter(
                $runner->getScans()->toArray(),
                static fn (EcoCheckpointScan $scan): bool => EcoScanResult::Success === $scan->getResult() && null !== $scan->getScannedAt(),
            );
            usort($scans, static fn (EcoCheckpointScan $a, EcoCheckpointScan $b): int => $a->getScannedAt() <=> $b->getScannedAt());

            $sequence = [];
            foreach ($scans as $scan) {
                $id = (int) $scan->getCheckpoint()->getId();
                if (!\in_array($id, $sequence, true)) {
                    $sequence[] = $id;
                }
            }

            for ($i = 1, $count = \count($sequence); $i < $count; ++$i) {
                $pairs[] = [$sequence[$i - 1], $sequence[$i]];
            }
        }

        return $pairs;
    }
}
