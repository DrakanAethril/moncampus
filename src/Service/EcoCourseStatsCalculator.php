<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EcoCheckpoint;
use App\Entity\EcoCourse;
use App\Entity\EcoRunner;
use App\Enum\EcoCheckpointType;
use App\Enum\EcoScanResult;

/**
 * The whole-course reading of a closed race (screen 1j): the eight headline KPIs, one row per
 * runner, one row per segment and one row per checkpoint.
 *
 * Deliberately built on top of the two per-runner services rather than beside them -
 * EcoRunnerStatsCalculator owns "how long/how far did this runner go" and EcoPerformanceAnalyzer
 * owns "what happened between two checkpoints", and both readings have to agree with the per-runner
 * screen (1i) the Détail link leads to.
 *
 * `gapSeconds` and `gapMeters` are optional on EcoRunnerRow because withGaps() adds them in a second
 * pass, once the reference run is known.
 *
 * @phpstan-import-type EcoLeg from EcoPerformanceAnalyzer
 *
 * @phpstan-type EcoSegmentAccumulator array{
 *     fromPosition: int,
 *     toPosition: int,
 *     fromLabel: string,
 *     toLabel: string,
 *     seconds: list<int>,
 *     bestSeconds: ?int,
 *     bestPseudo: ?string,
 *     detours: list<float>,
 *     minDetourMeters: ?float,
 *     minDetourPseudo: ?string,
 * }
 * @phpstan-type EcoRunnerRow array{
 *     runner: EcoRunner,
 *     pseudo: string,
 *     checkpointsValidated: int,
 *     checkpointsTotal: int,
 *     isComplete: bool,
 *     durationSeconds: ?int,
 *     distanceMeters: float,
 *     appExitCount: int,
 *     scanErrorCount: int,
 *     hasSos: bool,
 *     gapSeconds?: ?int,
 *     gapMeters?: ?float,
 * }
 */
class EcoCourseStatsCalculator
{
    public function __construct(
        private readonly EcoRunnerStatsCalculator $statsCalculator,
        private readonly EcoPerformanceAnalyzer $analyzer,
    ) {
    }

    /**
     * @return array{
     *     kpis: array<string, mixed>,
     *     runners: list<EcoRunnerRow>,
     *     segments: list<array<string, mixed>>,
     *     checkpoints: list<array<string, mixed>>,
     * }
     */
    public function calculate(EcoCourse $course): array
    {
        /** @var list<EcoRunner> $runners */
        $runners = array_values($course->getRunners()->toArray());
        $checkpointTotal = $course->getParcours()->getCheckpoints()->count();

        $rows = [];
        $segments = [];
        $searchSecondsByCheckpoint = [];

        foreach ($runners as $runner) {
            $stats = $this->statsCalculator->calculate($runner);
            $analysis = $this->analyzer->analyse($runner, $runners);
            $pseudo = $runner->getPseudo() ?? '';

            foreach ($analysis['legs'] as $leg) {
                $this->collectSegment($segments, $leg, $pseudo);

                if (null !== ($leg['searchSeconds'] ?? null)) {
                    $searchSecondsByCheckpoint[$leg['toCheckpointId']][] = (int) $leg['searchSeconds'];
                }
            }

            $rows[] = [
                'runner' => $runner,
                'pseudo' => $pseudo,
                'checkpointsValidated' => $stats['checkpointsValidated'],
                'checkpointsTotal' => $checkpointTotal,
                'isComplete' => $checkpointTotal > 0 && $stats['checkpointsValidated'] >= $checkpointTotal,
                // "Temps affiché même sur parcours incomplet (sans écart)" - a runner who never
                // crossed the finish line has no getFinishedAt(), so their time is counted up to
                // their last scan instead of left blank.
                'durationSeconds' => $stats['durationSeconds'] ?? $this->timeOnCourseSeconds($runner),
                'distanceMeters' => $stats['distanceMeters'],
                'appExitCount' => \count($stats['appEvents']),
                'scanErrorCount' => $stats['scanFailureCount'],
                // A runner who pressed SOS keeps a red row for the rest of the course's life, even
                // once the alert was marked handled (screen 1j: "ligne en rouge, stats normales").
                'hasSos' => null !== $runner->getSosAt(),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcasecmp($a['pseudo'], $b['pseudo']));

        $rows = $this->withGaps($rows);

        return [
            'kpis' => $this->kpis($runners, $rows),
            'runners' => $rows,
            'segments' => $this->finaliseSegments($segments),
            'checkpoints' => $this->checkpointRows($course, $runners, $searchSecondsByCheckpoint),
        ];
    }

    /**
     * Adds each row's gap to the reference: the best time among complete runs, and the shortest
     * distance among them ("meilleur parcours"). Incomplete runs still show their time - there is
     * simply nothing to compare it against (screen 1j).
     *
     * @param list<EcoRunnerRow> $rows
     *
     * @return list<EcoRunnerRow>
     */
    private function withGaps(array $rows): array
    {
        $bestDuration = $this->bestDurationSeconds($rows);
        $bestDistance = $this->bestDistanceMeters($rows);

        foreach ($rows as $index => $row) {
            $rows[$index]['gapSeconds'] = (null !== $bestDuration && $row['isComplete'] && null !== $row['durationSeconds'] && $row['durationSeconds'] > $bestDuration)
                ? $row['durationSeconds'] - $bestDuration
                : null;
            // Under 50 m the gap rounds to "+0,0 km" and says nothing - better no parenthesis.
            $gapMeters = (null !== $bestDistance && $row['isComplete']) ? $row['distanceMeters'] - $bestDistance : null;
            $rows[$index]['gapMeters'] = (null !== $gapMeters && $gapMeters >= 50.0) ? $gapMeters : null;
        }

        return $rows;
    }

    /**
     * @param list<EcoRunner>    $runners
     * @param list<EcoRunnerRow> $rows
     *
     * @return array<string, mixed>
     */
    private function kpis(array $runners, array $rows): array
    {
        $complete = array_values(array_filter($rows, static fn (array $row): bool => $row['isComplete']));
        // Both averages are taken over complete runs only, so each reads against the "best" tile
        // beside it: mixing in a runner who gave up half way would drag the average time down.
        $timed = array_values(array_filter($complete, static fn (array $row): bool => null !== $row['durationSeconds']));
        $travelled = array_values(array_filter($complete, static fn (array $row): bool => $row['distanceMeters'] > 0.0));

        $bestDurationRow = $this->pickBest($complete, static fn (array $row): ?float => null !== $row['durationSeconds'] ? (float) $row['durationSeconds'] : null);
        $bestDistanceRow = $this->pickBest($complete, static fn (array $row): ?float => $row['distanceMeters'] > 0.0 ? $row['distanceMeters'] : null);

        $sosRunners = array_values(array_filter($runners, static fn (EcoRunner $runner): bool => null !== $runner->getSosAt()));
        $sosHandled = array_values(array_filter($sosRunners, static fn (EcoRunner $runner): bool => !$runner->isSosActive()));

        return [
            'runnerCount' => \count($rows),
            'completeCount' => \count($complete),
            'completePercent' => [] !== $rows ? (int) round(100 * \count($complete) / \count($rows)) : 0,
            'alertCount' => \count($sosRunners),
            'sosHandledCount' => \count($sosHandled),
            'scanErrorCount' => (int) array_sum(array_column($rows, 'scanErrorCount')),
            'averageDurationSeconds' => [] !== $timed ? (int) round(array_sum(array_column($timed, 'durationSeconds')) / \count($timed)) : null,
            'bestDurationSeconds' => $bestDurationRow['durationSeconds'] ?? null,
            'bestDurationPseudo' => $bestDurationRow['pseudo'] ?? null,
            'averageDistanceMeters' => [] !== $travelled ? array_sum(array_column($travelled, 'distanceMeters')) / \count($travelled) : null,
            'bestDistanceMeters' => $bestDistanceRow['distanceMeters'] ?? null,
            'bestDistancePseudo' => $bestDistanceRow['pseudo'] ?? null,
        ];
    }

    /**
     * One row per segment actually run, keyed by direction: in free order D → 1 and D → 2 are two
     * different segments leaving the same point, and the screen lists both.
     *
     * @param array<string, EcoSegmentAccumulator> $segments
     * @param EcoLeg                               $leg
     */
    private function collectSegment(array &$segments, array $leg, string $pseudo): void
    {
        $key = $leg['fromCheckpointId'].'>'.$leg['toCheckpointId'];
        $segments[$key] ??= [
            'fromPosition' => $leg['fromPosition'],
            'toPosition' => $leg['toPosition'],
            'fromLabel' => $this->positionLabel($leg['fromPosition'], $leg['fromName']),
            'toLabel' => $this->positionLabel($leg['toPosition'], $leg['toName']),
            'seconds' => [],
            'bestSeconds' => null,
            'bestPseudo' => null,
            'detours' => [],
            'minDetourMeters' => null,
            'minDetourPseudo' => null,
        ];

        $seconds = (int) $leg['seconds'];
        $segments[$key]['seconds'][] = $seconds;
        if (null === $segments[$key]['bestSeconds'] || $seconds < $segments[$key]['bestSeconds']) {
            $segments[$key]['bestSeconds'] = $seconds;
            $segments[$key]['bestPseudo'] = $pseudo;
        }

        // "Détour" is the ground covered on top of the straight line, in metres - not the ratio the
        // per-runner screen shows, which reads badly once averaged over a whole class.
        $straight = $leg['straightMeters'] ?? null;
        if (null === $straight || $straight <= 0.0) {
            return;
        }

        $detour = max(0.0, $leg['travelledMeters'] - $straight);
        $segments[$key]['detours'][] = $detour;
        if (null === $segments[$key]['minDetourMeters'] || $detour < $segments[$key]['minDetourMeters']) {
            $segments[$key]['minDetourMeters'] = $detour;
            $segments[$key]['minDetourPseudo'] = $pseudo;
        }
    }

    /**
     * @param array<string, EcoSegmentAccumulator> $segments
     *
     * @return list<array<string, mixed>>
     */
    private function finaliseSegments(array $segments): array
    {
        $rows = [];
        foreach ($segments as $segment) {
            $rows[] = [
                'fromPosition' => $segment['fromPosition'],
                'toPosition' => $segment['toPosition'],
                'label' => $segment['fromLabel'].' → '.$segment['toLabel'],
                'runCount' => \count($segment['seconds']),
                'averageSeconds' => (int) round(array_sum($segment['seconds']) / \count($segment['seconds'])),
                'bestSeconds' => $segment['bestSeconds'],
                'bestPseudo' => $segment['bestPseudo'],
                'averageDetourMeters' => [] !== $segment['detours'] ? array_sum($segment['detours']) / \count($segment['detours']) : null,
                'minDetourMeters' => $segment['minDetourMeters'],
                'minDetourPseudo' => $segment['minDetourPseudo'],
            ];
        }

        // "Trié par point de départ du segment en commençant par D" - the Départ is position 0, so
        // sorting on the departure point puts it first on its own.
        usort($rows, static fn (array $a, array $b): int => [$a['fromPosition'], $a['toPosition']] <=> [$b['fromPosition'], $b['toPosition']]);

        return $rows;
    }

    /**
     * @param list<EcoRunner>            $runners
     * @param array<int, list<int>>      $searchSecondsByCheckpoint
     *
     * @return list<array<string, mixed>>
     */
    private function checkpointRows(EcoCourse $course, array $runners, array $searchSecondsByCheckpoint): array
    {
        $checkpoints = $course->getParcours()->getCheckpoints()->toArray();
        usort($checkpoints, static fn (EcoCheckpoint $a, EcoCheckpoint $b): int => $a->getPosition() <=> $b->getPosition());

        $foundBy = [];
        $scanDistances = [];
        $errors = [];

        foreach ($runners as $runner) {
            $validatedHere = [];
            foreach ($runner->getScans() as $scan) {
                $checkpointId = (int) $scan->getCheckpoint()->getId();

                if (EcoScanResult::Success !== $scan->getResult()) {
                    $errors[$checkpointId] = ($errors[$checkpointId] ?? 0) + 1;

                    continue;
                }

                if (null !== $scan->getDistanceMeters()) {
                    $scanDistances[$checkpointId][] = (float) $scan->getDistanceMeters();
                }

                // A retried checkpoint still counts as found once.
                $validatedHere[$checkpointId] = true;
            }

            foreach (array_keys($validatedHere) as $checkpointId) {
                $foundBy[$checkpointId] = ($foundBy[$checkpointId] ?? 0) + 1;
            }
        }

        $rows = [];
        foreach ($checkpoints as $checkpoint) {
            $id = (int) $checkpoint->getId();
            $searchSeconds = $searchSecondsByCheckpoint[$id] ?? [];
            $distances = $scanDistances[$id] ?? [];

            $rows[] = [
                'label' => $this->positionLabel($checkpoint->getPosition(), $checkpoint->getName() ?? '', $checkpoint->getType()),
                'name' => $checkpoint->getName() ?? '',
                'foundBy' => $foundBy[$id] ?? 0,
                'runnerCount' => \count($runners),
                // The Départ has no leg leading to it, so it never has a search time to average.
                'averageSearchSeconds' => [] !== $searchSeconds ? (int) round(array_sum($searchSeconds) / \count($searchSeconds)) : null,
                'averageScanDistanceMeters' => [] !== $distances ? array_sum($distances) / \count($distances) : null,
                'errorCount' => $errors[$id] ?? 0,
                'isStartOrFinish' => EcoCheckpointType::Checkpoint !== $checkpoint->getType(),
            ];
        }

        return $rows;
    }

    /**
     * The short "D" / "4" / "A" label, from a checkpoint's position when its type isn't at hand
     * (legs only carry positions and names).
     */
    private function positionLabel(int $position, string $name, ?EcoCheckpointType $type = null): string
    {
        $type ??= match (true) {
            0 === $position => EcoCheckpointType::Start,
            default => EcoCheckpointType::Checkpoint,
        };

        return match ($type) {
            EcoCheckpointType::Start => 'D',
            EcoCheckpointType::Finish => 'A',
            // A leg's far end can be the Arrivée, which positions alone cannot tell from a numbered
            // balise - its name is the only thing left to go on.
            EcoCheckpointType::Checkpoint => 'Arrivée' === $name ? 'A' : (string) $position,
        };
    }

    /**
     * How long an unfinished runner was actually out on the course: from their start to their last
     * scan. Null while they never scanned anything - there is no elapsed time to speak of then.
     */
    private function timeOnCourseSeconds(EcoRunner $runner): ?int
    {
        $startedAt = $runner->getStartedAt();
        if (null === $startedAt) {
            return null;
        }

        $lastScanAt = null;
        foreach ($runner->getScans() as $scan) {
            $scannedAt = $scan->getScannedAt();
            if (null !== $scannedAt && (null === $lastScanAt || $scannedAt > $lastScanAt)) {
                $lastScanAt = $scannedAt;
            }
        }

        return null !== $lastScanAt ? max(0, $lastScanAt->getTimestamp() - $startedAt->getTimestamp()) : null;
    }

    /** @param list<EcoRunnerRow> $rows */
    private function bestDurationSeconds(array $rows): ?int
    {
        $best = null;
        foreach ($rows as $row) {
            if (!$row['isComplete'] || null === $row['durationSeconds']) {
                continue;
            }
            $best = null === $best ? $row['durationSeconds'] : min($best, $row['durationSeconds']);
        }

        return $best;
    }

    /** @param list<EcoRunnerRow> $rows */
    private function bestDistanceMeters(array $rows): ?float
    {
        $best = null;
        foreach ($rows as $row) {
            if (!$row['isComplete'] || $row['distanceMeters'] <= 0.0) {
                continue;
            }
            $best = null === $best ? $row['distanceMeters'] : min($best, $row['distanceMeters']);
        }

        return $best;
    }

    /**
     * @param list<EcoRunnerRow>            $rows
     * @param callable(EcoRunnerRow): ?float $value
     *
     * @return EcoRunnerRow|null
     */
    private function pickBest(array $rows, callable $value): ?array
    {
        $best = null;
        $bestValue = null;
        foreach ($rows as $row) {
            $candidate = $value($row);
            if (null === $candidate) {
                continue;
            }
            if (null === $bestValue || $candidate < $bestValue) {
                $bestValue = $candidate;
                $best = $row;
            }
        }

        return $best;
    }
}
