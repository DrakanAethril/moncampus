<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Enum\AttendanceState;

/**
 * What a run of statements pays, unit by unit (§5.1) - pure arithmetic over three states.
 *
 * Two rules and both carry a decision:
 *
 * - **An out-of-scope unit leaves the denominator instead of costing points.** Work placement,
 *   sick leave, an arrival in November: without it the game punishes exactly the people it must
 *   not, and it does not recover from that.
 * - **An out-of-scope unit does not break a streak either.** It is not a fault, so a fortnight in
 *   the company between two clean weeks leaves the run standing. Only « pas net » resets it.
 *
 * The streak pays each consecutive clean unit beyond the first, and the bonus accumulated inside
 * one run stops at the ceiling - four bonus units at the design's values. Beyond that a run pays
 * its units and nothing more, which is what keeps a long quiet term from outweighing everything
 * else in the family.
 */
final class GameAttendanceScale
{
    /**
     * @param list<array{state: AttendanceState, weeks: int}> $units in chronological order
     *
     * @return list<array{points: int, streak: int, possible: int}> one entry per unit, same order
     */
    public function points(array $units, int $unitPoints, int $streakPoints, int $streakCap): array
    {
        $result = [];
        $run = 0;
        $runBonus = 0;

        foreach ($units as $unit) {
            $weeks = max(1, $unit['weeks']);

            if (AttendanceState::OutOfScope === $unit['state']) {
                // Neutral on every count: no points, no denominator, and the run is left standing.
                $result[] = ['points' => 0, 'streak' => 0, 'possible' => 0];

                continue;
            }

            if (AttendanceState::NotClean === $unit['state']) {
                $run = 0;
                $runBonus = 0;
                $result[] = ['points' => 0, 'streak' => 0, 'possible' => $unitPoints * $weeks];

                continue;
            }

            ++$run;
            $bonus = 0;

            if ($run > 1 && $runBonus < $streakCap) {
                $bonus = min($streakPoints, $streakCap - $runBonus);
                $runBonus += $bonus;
            }

            $result[] = [
                'points' => $unitPoints * $weeks + $bonus,
                'streak' => $bonus,
                'possible' => $unitPoints * $weeks,
            ];
        }

        return $result;
    }

    /**
     * The denominator of the family: what the units that concerned this student could have paid.
     *
     * The streak is deliberately absent from it - it is a bonus laid on top, and
     * App\Service\Game\GameScoreCalculator caps the rate at 1 rather than letting a perfect run
     * read as 111 %.
     *
     * @param list<array{points: int, streak: int, possible: int}> $scale
     */
    public function possible(array $scale): int
    {
        return array_sum(array_column($scale, 'possible'));
    }
}
