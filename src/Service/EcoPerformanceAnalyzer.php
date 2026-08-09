<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EcoCheckpoint;
use App\Entity\EcoCheckpointScan;
use App\Entity\EcoPositionPing;
use App\Entity\EcoRunner;
use App\Enum\EcoScanResult;
use App\Repository\EcoPositionPingRepository;

/**
 * The leg-by-leg reading of one runner's race, on the results screen: how long each leg took, how
 * far off the fastest runner it was, how straight the route was, how long the flag took to find,
 * and where the runner stopped.
 *
 * A leg is bounded by two consecutive *validated* scans of that runner, in the order they made
 * them - never in the parcours' own order. In ordre libre and in mode score every runner writes
 * their own sequence, so the only thing that makes two runners' times comparable is that they
 * covered the same pair of checkpoints; the pair is what legs are keyed and compared on, taken
 * unordered since running B→A covers the same ground as A→B.
 */
class EcoPerformanceAnalyzer
{
    /**
     * A runner within this distance of the checkpoint is looking for the flag rather than running
     * towards it - what the "recherche" column measures the time of.
     */
    private const float SEARCH_RADIUS_METERS = 35.0;

    /** Below this speed the runner is not progressing, whatever the GPS wander says. */
    private const float STOP_SPEED_KMH = 1.5;

    /** Slower than the above for at least this long, and it is a stop rather than a slow patch. */
    private const int STOP_MIN_SECONDS = 20;

    /**
     * Two fixes further apart than this did not record a runner standing still - they record the
     * phone having said nothing (no signal, app closed, battery saver). Counting that silence as a
     * stop would blame the runner for a hole in their own trace.
     */
    private const int MAX_FIX_GAP_SECONDS = 120;

    public function __construct(
        private readonly EcoPositionPingRepository $pingRepository,
        private readonly EcoDistanceCalculator $distanceCalculator,
        private readonly EcoTraceCleaner $traceCleaner,
    ) {
    }

    /**
     * @param list<EcoRunner> $courseRunners every runner of the course, for the "écart au meilleur"
     *
     * @return array{legs: list<array<string, mixed>>, stops: list<array<string, mixed>>, stopSecondsTotal: int, searchSecondsTotal: int}
     */
    public function analyse(EcoRunner $runner, array $courseRunners): array
    {
        $pings = $this->pingRepository->findForRunner($runner);
        $legs = $this->legsOf($runner, $pings);
        $bestTimes = $this->bestLegSeconds($courseRunners);

        foreach ($legs as $index => $leg) {
            $best = $bestTimes[$leg['pairKey']] ?? null;
            $legs[$index]['bestSeconds'] = $best;
            $legs[$index]['gapSeconds'] = null !== $best ? $leg['seconds'] - $best : null;
            $legs[$index]['isBest'] = null !== $best && $leg['seconds'] <= $best;
        }

        $stops = $this->stopsOf($pings);

        return [
            'legs' => $legs,
            'stops' => $stops,
            'stopSecondsTotal' => array_sum(array_column($stops, 'seconds')),
            'searchSecondsTotal' => (int) array_sum(array_map(
                static fn (array $leg): int => $leg['searchSeconds'] ?? 0,
                $legs,
            )),
        ];
    }

    /**
     * One entry per leg actually run: from where to where, in how long, over what distance, how
     * straight, and how much of it was spent hunting for the flag at the far end.
     *
     * @param list<EcoPositionPing> $pings
     *
     * @return list<array<string, mixed>>
     */
    private function legsOf(EcoRunner $runner, array $pings): array
    {
        $validated = $this->validatedScans($runner);
        $legs = [];

        for ($i = 1; $i < \count($validated); ++$i) {
            $from = $validated[$i - 1];
            $to = $validated[$i];
            $fromCheckpoint = $from->getCheckpoint();
            $toCheckpoint = $to->getCheckpoint();
            $startedAt = $from->getScannedAt();
            $endedAt = $to->getScannedAt();

            if (null === $startedAt || null === $endedAt) {
                continue;
            }

            $legPings = $this->pingsBetween($pings, $startedAt, $endedAt);
            // Measured from checkpoint to checkpoint, not from first fix to last - see legPoints().
            $points = $this->legPoints($legPings, $fromCheckpoint, $toCheckpoint);
            $travelled = $this->traceCleaner->travelledMeters($points);
            $straight = $this->straightLineMeters($fromCheckpoint, $toCheckpoint);
            $seconds = max(0, $endedAt->getTimestamp() - $startedAt->getTimestamp());

            $legs[] = [
                'fromName' => $fromCheckpoint->getName() ?? '',
                'toName' => $toCheckpoint->getName() ?? '',
                'pairKey' => $this->pairKey($fromCheckpoint, $toCheckpoint),
                'seconds' => $seconds,
                'travelledMeters' => $travelled,
                'straightMeters' => $straight,
                // Below ~50 m the ratio says more about GPS wander than about the route taken.
                'detourRatio' => (null !== $straight && $straight > 50.0 && $travelled > 0.0)
                    ? $travelled / $straight
                    : null,
                'searchSeconds' => $this->searchSecondsAt($toCheckpoint, $legPings, $endedAt),
                // Kept so the map can redraw one leg on its own - it is what lets the best and the
                // worst detour be picked out in colour.
                'points' => $points,
                // Lets the map put a leg's search time on the checkpoint it was spent looking for.
                'toCheckpointId' => (int) $toCheckpoint->getId(),
                // Both ends, so the course statistics can group legs by the direction they were
                // run in - unlike pairKey, which is deliberately direction-agnostic.
                'fromCheckpointId' => (int) $fromCheckpoint->getId(),
                'fromPosition' => $fromCheckpoint->getPosition(),
                'toPosition' => $toCheckpoint->getPosition(),
            ];
        }

        return $legs;
    }

    /**
     * The scans that opened and closed each leg: the first successful scan of each checkpoint, in
     * the order the runner made them.
     *
     * @return list<EcoCheckpointScan>
     */
    private function validatedScans(EcoRunner $runner): array
    {
        $scans = array_filter(
            $runner->getScans()->toArray(),
            static fn (EcoCheckpointScan $scan): bool => EcoScanResult::Success === $scan->getResult() && null !== $scan->getScannedAt(),
        );
        usort($scans, static fn (EcoCheckpointScan $a, EcoCheckpointScan $b): int => $a->getScannedAt() <=> $b->getScannedAt());

        $firstPerCheckpoint = [];
        foreach ($scans as $scan) {
            $checkpointId = (int) $scan->getCheckpoint()->getId();
            if (!isset($firstPerCheckpoint[$checkpointId])) {
                $firstPerCheckpoint[$checkpointId] = $scan;
            }
        }

        return array_values($firstPerCheckpoint);
    }

    /**
     * Fastest time recorded on each pair of checkpoints across the whole course - what a leg's
     * "écart au meilleur" is measured against. A pair nobody else ran simply has no best, and the
     * screen shows a dash rather than pretending the runner won it.
     *
     * @param list<EcoRunner> $courseRunners
     *
     * @return array<string, int>
     */
    private function bestLegSeconds(array $courseRunners): array
    {
        $best = [];

        foreach ($courseRunners as $runner) {
            $validated = $this->validatedScans($runner);

            for ($i = 1; $i < \count($validated); ++$i) {
                $startedAt = $validated[$i - 1]->getScannedAt();
                $endedAt = $validated[$i]->getScannedAt();

                if (null === $startedAt || null === $endedAt) {
                    continue;
                }

                $key = $this->pairKey($validated[$i - 1]->getCheckpoint(), $validated[$i]->getCheckpoint());
                $seconds = max(0, $endedAt->getTimestamp() - $startedAt->getTimestamp());

                if (!isset($best[$key]) || $seconds < $best[$key]) {
                    $best[$key] = $seconds;
                }
            }
        }

        return $best;
    }

    /** Direction-agnostic key: A→B and B→A cover the same ground, so they compare against each other. */
    private function pairKey(EcoCheckpoint $from, EcoCheckpoint $to): string
    {
        $ids = [(int) $from->getId(), (int) $to->getId()];
        sort($ids);

        return implode('-', $ids);
    }

    private function straightLineMeters(EcoCheckpoint $from, EcoCheckpoint $to): ?float
    {
        if (!$from->isLocated() || !$to->isLocated()) {
            return null;
        }

        return $this->distanceCalculator->distanceMeters(
            (float) $from->getLatitude(),
            (float) $from->getLongitude(),
            (float) $to->getLatitude(),
            (float) $to->getLongitude(),
        );
    }

    /**
     * How long the runner circled the flag before validating it: from the moment they first came
     * within SEARCH_RADIUS_METERS and stayed around, up to the scan itself.
     *
     * @param list<EcoPositionPing> $legPings
     */
    private function searchSecondsAt(EcoCheckpoint $checkpoint, array $legPings, \DateTimeImmutable $scannedAt): ?int
    {
        if (!$checkpoint->isLocated() || [] === $legPings) {
            return null;
        }

        $enteredAt = null;
        foreach ($legPings as $ping) {
            $distance = $this->distanceCalculator->distanceMeters(
                (float) $ping->getLatitude(),
                (float) $ping->getLongitude(),
                (float) $checkpoint->getLatitude(),
                (float) $checkpoint->getLongitude(),
            );

            if ($distance <= self::SEARCH_RADIUS_METERS) {
                // First fix inside the circle that is never left again before the scan - a runner
                // who passes through the area early and comes back later is timed from the return.
                $enteredAt ??= $ping->getRecordedAt();

                continue;
            }

            $enteredAt = null;
        }

        if (null === $enteredAt) {
            return null;
        }

        return max(0, $scannedAt->getTimestamp() - $enteredAt->getTimestamp());
    }

    /**
     * Where the runner stood still: consecutive fixes under STOP_SPEED_KMH for at least
     * STOP_MIN_SECONDS. Each stop is placed on the map at its first fix.
     *
     * @param list<EcoPositionPing> $pings
     *
     * @return list<array{latitude: float, longitude: float, seconds: int, at: string}>
     */
    private function stopsOf(array $pings): array
    {
        $stops = [];
        $anchor = null;

        for ($i = 1; $i < \count($pings); ++$i) {
            $previous = $pings[$i - 1];
            $current = $pings[$i];
            $seconds = $current->getRecordedAt()->getTimestamp() - $previous->getRecordedAt()->getTimestamp();

            if ($seconds <= 0) {
                continue;
            }

            if ($seconds > self::MAX_FIX_GAP_SECONDS) {
                // A hole in the trace closes whatever stop was open and starts nothing.
                $stops[] = $this->closeStop($anchor, $previous);
                $anchor = null;

                continue;
            }

            $metres = $this->distanceCalculator->distanceMeters(
                (float) $previous->getLatitude(),
                (float) $previous->getLongitude(),
                (float) $current->getLatitude(),
                (float) $current->getLongitude(),
            );
            $speedKmh = ($metres / $seconds) * 3.6;

            if ($speedKmh <= self::STOP_SPEED_KMH) {
                $anchor ??= $previous;

                continue;
            }

            $stops[] = $this->closeStop($anchor, $previous);
            $anchor = null;
        }

        // A stop still open at the last fix - the runner stood there until their trace ends.
        if (null !== $anchor && [] !== $pings) {
            $stops[] = $this->closeStop($anchor, $pings[\count($pings) - 1]);
        }

        return array_values(array_filter($stops));
    }

    /** @return array{latitude: float, longitude: float, seconds: int, at: string}|null */
    private function closeStop(?EcoPositionPing $anchor, EcoPositionPing $until): ?array
    {
        if (null === $anchor) {
            return null;
        }

        $seconds = $until->getRecordedAt()->getTimestamp() - $anchor->getRecordedAt()->getTimestamp();

        if ($seconds < self::STOP_MIN_SECONDS) {
            return null;
        }

        return [
            'latitude' => (float) $anchor->getLatitude(),
            'longitude' => (float) $anchor->getLongitude(),
            'seconds' => $seconds,
            'at' => $anchor->getRecordedAt()->format('H:i:s'),
        ];
    }

    /**
     * The leg as coordinates: its fixes, bounded by the two checkpoints the runner validated at
     * each end. Without those bounds the leg loses up to one fix-interval of ground on each side,
     * which alone can drop the detour ratio under 1 - a route shorter than the straight line,
     * which cannot happen.
     *
     * @param list<EcoPositionPing> $legPings
     *
     * @return list<array{float, float}>
     */
    private function legPoints(array $legPings, EcoCheckpoint $from, EcoCheckpoint $to): array
    {
        $points = array_map(
            static fn (EcoPositionPing $ping): array => [(float) $ping->getLatitude(), (float) $ping->getLongitude()],
            $legPings,
        );

        if ($from->isLocated()) {
            array_unshift($points, [(float) $from->getLatitude(), (float) $from->getLongitude()]);
        }
        if ($to->isLocated()) {
            $points[] = [(float) $to->getLatitude(), (float) $to->getLongitude()];
        }

        return $points;
    }

    /**
     * @param list<EcoPositionPing> $pings
     *
     * @return list<EcoPositionPing>
     */
    private function pingsBetween(array $pings, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return array_values(array_filter(
            $pings,
            static fn (EcoPositionPing $ping): bool => $ping->getRecordedAt() >= $from && $ping->getRecordedAt() <= $to,
        ));
    }
}
