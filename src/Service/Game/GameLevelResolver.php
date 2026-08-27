<?php

declare(strict_types=1);

namespace App\Service\Game;

/**
 * A running total of points to a level, and to how far the next one is.
 *
 * There is nothing to calibrate here any more, and that is the decision (2026-08-28). Until then a
 * closure converted an index into XP through a coefficient read off the number of evaluation
 * periods a cursus counted, so a formation in terms and one in semesters could reach level 6 alike.
 * Points are now credited on the day they are earned, and the total of the ledger *is* the total -
 * one conversion less, one calendar less, and a student changing formation arrives at the level
 * their own points give them there.
 *
 * The total only ever grows: no malus touches it, no school year resets it (§5.6).
 */
final class GameLevelResolver
{
    public function resolve(int $xpTotal): GameLevelProgress
    {
        $levels = GameLevels::all();
        $current = $levels[0];
        $next = null;

        foreach ($levels as $index => $level) {
            if ($xpTotal >= $level->xpMin) {
                $current = $level;
                $next = $levels[$index + 1] ?? null;
            }
        }

        if (null === $next) {
            return new GameLevelProgress($current, null, $xpTotal, $xpTotal - $current->xpMin, null, 1.0);
        }

        $span = $next->xpMin - $current->xpMin;
        $into = $xpTotal - $current->xpMin;

        return new GameLevelProgress(
            $current,
            $next,
            $xpTotal,
            $into,
            $next->xpMin - $xpTotal,
            $span > 0 ? min(1.0, max(0.0, $into / $span)) : 0.0,
        );
    }
}
