<?php

declare(strict_types=1);

namespace App\Service\Game;

/**
 * The index of a period, and the single reason this class holds no entity at all.
 *
 * design/validated/gamification.md §2: **one earns points, one is ranked on a rate**. A point is
 * the unit inside a family; the rate is points earned over points it was possible to earn for that
 * student; the index is the weighted mean of the rates, out of 100. Ranking on totals would rank
 * availability - the apprentice in the company two weeks out of four, the student who arrived in
 * November - and would structurally exclude the very people the game is meant to encourage.
 *
 * The behaviour worth stating here rather than only in the design: **a family with no data leaves
 * the calculation and its weight is redistributed pro rata**. Nobody making an attendance statement
 * is not a failure and produces no message - the index is simply computed on 43 / 36 / 21 and stays
 * a number out of 100, comparable from one student to the next. It is what makes the game usable in
 * a class where a single teacher plays it.
 *
 * Pure arithmetic on primitives, which is what makes it testable before anything exists
 * (tests/Service/Game/GameScoreCalculatorTest.php).
 */
final class GameScoreCalculator
{
    /**
     * @param array<string, int>  $earned   family value => points acquired, possibly negative
     * @param array<string, ?int> $possible family value => denominator; null or 0 means "no data"
     * @param array<string, int>  $weights  family value => configured weight, out of 100
     */
    public function compute(array $earned, array $possible, array $weights): GameScore
    {
        $rates = [];
        $counted = [];

        foreach ($weights as $family => $weight) {
            $denominator = $possible[$family] ?? null;

            // The one rule the rest of the design hangs from. `0` is treated exactly as `null`: a
            // denominator of zero is a family nothing could have been earned in, and dividing by it
            // is the crash this branch exists to make impossible.
            if (null === $denominator || $denominator <= 0) {
                continue;
            }

            // Floors at 0 and caps at 1 (§5.6): a malus never takes a family below zero, and a
            // bonus that is not part of the possible - the attendance streak - never takes it above.
            $rates[$family] = min(1.0, max(0.0, (float) ($earned[$family] ?? 0)) / $denominator);
            $counted[$family] = $weight;
        }

        $total = array_sum($counted);

        if ([] === $counted || $total <= 0) {
            return new GameScore(0, [], []);
        }

        $index = 0.0;
        foreach ($rates as $family => $rate) {
            $index += $rate * $counted[$family] / $total;
        }

        return new GameScore((int) round($index * 100), $rates, $this->redistribute($counted, $total));
    }

    /**
     * The weights as the screens print them: whole numbers still adding up to 100.
     *
     * Largest remainder rather than a plain round on each, because three rounded thirds add up to
     * 99 and an information panel announcing "43 / 36 / 21" that sums to 99 is the kind of detail
     * that makes a reader stop trusting the rest of the number.
     *
     * @param array<string, int> $counted
     *
     * @return array<string, int>
     */
    private function redistribute(array $counted, int $total): array
    {
        $exact = [];
        $shares = [];
        foreach ($counted as $family => $weight) {
            $exact[$family] = $weight * 100 / $total;
            $shares[$family] = (int) floor($exact[$family]);
        }

        $remaining = 100 - array_sum($shares);
        if ($remaining > 0) {
            $remainders = [];
            foreach ($exact as $family => $value) {
                $remainders[$family] = $value - floor($value);
            }
            arsort($remainders);

            foreach (array_keys($remainders) as $family) {
                if ($remaining <= 0) {
                    break;
                }
                ++$shares[$family];
                --$remaining;
            }
        }

        return $shares;
    }
}
