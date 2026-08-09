<?php

namespace App\Service;

use App\Entity\EcoAppEvent;
use App\Entity\EcoCheckpointScan;
use App\Entity\EcoPositionPing;
use App\Entity\EcoRunner;
use App\Enum\EcoScanResult;
use App\Repository\EcoAppEventRepository;
use App\Repository\EcoPositionPingRepository;

/**
 * Builds the per-participant KPIs/trace shown on the results screen (1i): duration, distance
 * (summed from the GPS trace, see EcoDistanceCalculator), average speed, checkpoint pass/fail
 * counts, and app-exit summary.
 */
class EcoRunnerStatsCalculator
{
    public function __construct(
        private readonly EcoPositionPingRepository $pingRepository,
        private readonly EcoAppEventRepository $appEventRepository,
        private readonly EcoTraceCleaner $traceCleaner,
    ) {
    }

    /** @return array{durationSeconds: ?int, distanceMeters: float, averageSpeedKmh: ?float, elevation: ?array{gain: float, loss: float}, checkpointsValidated: int, checkpointsTotal: int, scanFailureCount: int, appEvents: list<EcoAppEvent>, pings: list<EcoPositionPing>} */
    public function calculate(EcoRunner $runner): array
    {
        $pings = $this->pingRepository->findForRunner($runner);
        // Filtered rather than summed fix by fix: see EcoTraceCleaner for why the raw sum reads
        // several hundred metres long on a trace that never left the wood.
        $distanceMeters = $this->traceCleaner->travelledMeters(array_map(
            static fn (EcoPositionPing $ping): array => [(float) $ping->getLatitude(), (float) $ping->getLongitude()],
            $pings,
        ));

        $durationSeconds = null !== $runner->getStartedAt() && null !== $runner->getFinishedAt()
            ? $runner->getFinishedAt()->getTimestamp() - $runner->getStartedAt()->getTimestamp()
            : null;

        $averageSpeedKmh = (null !== $durationSeconds && $durationSeconds > 0)
            ? ($distanceMeters / 1000) / ($durationSeconds / 3600)
            : null;

        $scans = $runner->getScans()->toArray();
        $successfulCheckpointIds = array_unique(array_map(
            static fn (EcoCheckpointScan $scan): int => $scan->getCheckpoint()->getId(),
            array_filter($scans, static fn (EcoCheckpointScan $scan): bool => EcoScanResult::Success === $scan->getResult()),
        ));
        $failureCount = \count(array_filter($scans, static fn (EcoCheckpointScan $scan): bool => EcoScanResult::Success !== $scan->getResult()));

        return [
            'durationSeconds' => $durationSeconds,
            'distanceMeters' => $distanceMeters,
            'averageSpeedKmh' => $averageSpeedKmh,
            'elevation' => $this->traceCleaner->elevation(array_map(
                static fn (EcoPositionPing $ping): ?float => $ping->getAltitude(),
                $pings,
            )),
            'checkpointsValidated' => \count($successfulCheckpointIds),
            'checkpointsTotal' => $runner->getCourse()->getParcours()->getCheckpoints()->count(),
            'scanFailureCount' => $failureCount,
            'appEvents' => $this->appEventRepository->findBy(['runner' => $runner], ['leftAt' => 'ASC']),
            'pings' => $pings,
        ];
    }
}
