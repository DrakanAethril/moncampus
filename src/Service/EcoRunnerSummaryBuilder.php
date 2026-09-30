<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EcoCheckpoint;
use App\Entity\EcoCheckpointScan;
use App\Entity\EcoRunner;
use App\Enum\EcoCheckpointType;
use App\Enum\EcoScanResult;

/**
 * The recap a runner reads on their own phone once they have scanned the finish - the same
 * figures as the teacher's results screen (EcoRunnerStatsCalculator, EcoPerformanceAnalyzer), cut
 * down to what one runner can make sense of standing at the finish line.
 *
 * Two readings differ from the teacher's on purpose:
 * - the checkpoints counted are the regular ones, never Départ and Arrivée, so the recap says
 *   « 7/8 » like the header of the race screen did a minute earlier;
 * - no rank and no gap to the best: the race is still running for everybody else, and a position
 *   read at the finish would change under the runner's feet.
 *
 * The climb is the phone's own until the course is closed and the IGN has read the trace
 * (`elevationSource` says which), exactly as on the results screen.
 *
 * @phpstan-type EcoRunnerSummary array{
 *     pseudo: string,
 *     courseName: string,
 *     parcoursName: string,
 *     mode: string,
 *     startedAt: ?string,
 *     finishedAt: ?string,
 *     durationSeconds: ?int,
 *     distanceMeters: int,
 *     averageSpeedKmh: ?float,
 *     elevationGain: ?int,
 *     elevationLoss: ?int,
 *     elevationSource: ?string,
 *     checkpointsValidated: int,
 *     checkpointsTotal: int,
 *     scanFailureCount: int,
 *     stopCount: int,
 *     stopSeconds: int,
 *     legs: list<array{fromName: string, toName: string, seconds: int, distanceMeters: int}>,
 * }
 */
class EcoRunnerSummaryBuilder
{
    public function __construct(
        private readonly EcoRunnerStatsCalculator $statsCalculator,
        private readonly EcoPerformanceAnalyzer $analyzer,
    ) {
    }

    /** @return EcoRunnerSummary */
    public function build(EcoRunner $runner): array
    {
        $course = $runner->getCourse();
        $stats = $this->statsCalculator->calculate($runner);
        // Compared with nobody but themselves: see the class docblock.
        $analysis = $this->analyzer->analyse($runner, [$runner]);

        $regular = array_filter(
            $course->getRaceCheckpoints(),
            static fn (EcoCheckpoint $checkpoint): bool => EcoCheckpointType::Checkpoint === $checkpoint->getType(),
        );
        $validatedRegular = array_unique(array_map(
            static fn (EcoCheckpointScan $scan): int => (int) $scan->getCheckpoint()->getId(),
            array_filter(
                $runner->getScans()->toArray(),
                static fn (EcoCheckpointScan $scan): bool => EcoScanResult::Success === $scan->getResult()
                    && EcoCheckpointType::Checkpoint === $scan->getCheckpoint()->getType(),
            ),
        ));

        $elevation = $stats['elevation'];

        return [
            'pseudo' => $runner->getPseudo() ?? '',
            'courseName' => $course->getName() ?? '',
            'parcoursName' => $course->getParcours()->getName() ?? '',
            // As the runner app knows it - see EcoCourse::runnerMode().
            'mode' => $course->runnerMode(),
            'startedAt' => $runner->getStartedAt()?->format(\DateTimeInterface::ATOM),
            'finishedAt' => $runner->getFinishedAt()?->format(\DateTimeInterface::ATOM),
            'durationSeconds' => $stats['durationSeconds'],
            'distanceMeters' => (int) round($stats['distanceMeters']),
            'averageSpeedKmh' => null !== $stats['averageSpeedKmh'] ? round($stats['averageSpeedKmh'], 1) : null,
            'elevationGain' => null !== $elevation ? (int) round($elevation['gain']) : null,
            'elevationLoss' => null !== $elevation ? (int) round($elevation['loss']) : null,
            'elevationSource' => $elevation['source'] ?? null,
            'checkpointsValidated' => \count($validatedRegular),
            'checkpointsTotal' => \count($regular),
            'scanFailureCount' => $stats['scanFailureCount'],
            'stopCount' => \count($analysis['stops']),
            'stopSeconds' => $analysis['stopSecondsTotal'],
            'legs' => array_map(static fn (array $leg): array => [
                'fromName' => $leg['fromName'],
                'toName' => $leg['toName'],
                'seconds' => $leg['seconds'],
                'distanceMeters' => (int) round($leg['travelledMeters']),
            ], $analysis['legs']),
        ];
    }
}
