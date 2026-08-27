<?php

declare(strict_types=1);

namespace App\Service\Game;

/**
 * XP to level, and index to XP.
 *
 * The second half is the one that carries a decision (§4, decision 2). The game borrows the
 * school's calendar rather than inventing one, so a cursus is worth as many closures as the
 * formation has evaluation periods - four semesters here, six terms elsewhere. For level 6 to stay
 * reachable on both, the XP paid at each closure scales:
 *
 *     XP de période = indice x (3600 / nombre de périodes du cursus) / 100
 *
 * A perfect cursus is therefore always 3 600 XP. On four semesters the coefficient happens to be
 * x9 - **the formula is wired, never the 9**, which is exactly what a formation in terms would
 * break.
 *
 * XP only ever grows: no malus touches it, no school year resets it (§5.6).
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

    /**
     * The XP one closure pays for an index, on a cursus of $periodCount periods.
     *
     * A formation with no period group answers 0 rather than guessing a count: nothing can be
     * closed on a calendar that does not exist, and inventing a divisor here would quietly pay a
     * whole cursus for one period.
     */
    public function xpForIndex(int $index, int $periodCount): int
    {
        if ($periodCount <= 0) {
            return 0;
        }

        return (int) round(max(0, $index) * (GameLevels::CURSUS_CAP / $periodCount) / 100);
    }

    /** The multiplier the settings screen prints next to the number of periods (« x9 »). */
    public function coefficient(int $periodCount): ?float
    {
        return $periodCount > 0 ? GameLevels::CURSUS_CAP / $periodCount / 100 : null;
    }
}
