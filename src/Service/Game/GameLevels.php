<?php

declare(strict_types=1);

namespace App\Service\Game;

/**
 * The six levels, in code, exactly as design/design_handoff_gamification/data/gamification.json
 * carries them.
 *
 * The XP thresholds are **common to the whole establishment** and are not a per-program setting -
 * only the wording is (App\Entity\GameLevelLabel). That is deliberate: a level is what an avatar
 * shows in every screen of the application, and a threshold moving from one formation to the next
 * would make the ring mean nothing.
 *
 * The cursus cap is invariant at 3 600 XP whatever the number of periods, which is what
 * App\Service\Game\GameLevelResolver::xpForIndex() computes and why the coefficient is never
 * written down as a number.
 */
final class GameLevels
{
    /** A perfect cursus, whatever its number of periods (§4, decision 2). */
    public const int CURSUS_CAP = 3600;

    /** @var list<GameLevel>|null */
    private static ?array $levels = null;

    /** @return list<GameLevel> */
    public static function all(): array
    {
        return self::$levels ??= [
            new GameLevel(1, 0, '#98a6b3', '#eef2f6', 0.45, '#5b6c79', '#eef2f6'),
            new GameLevel(2, 300, '#6ea3cd', '#e3f0fa', 0.5, '#3f88bd', '#e3f0fa'),
            new GameLevel(3, 700, '#1B6BA8', '#6ea3cd', 0.55, '#12507e', '#cfe3f2'),
            new GameLevel(4, 1200, '#1f7a54', '#9fd4bb', 0.55, '#155c3f', '#d7efe3'),
            new GameLevel(5, 1800, '#c9a04e', '#f3ddb0', 0.65, '#8a6a1f', '#f3ddb0'),
            new GameLevel(6, 2500, '#c9a04e', '#12344d', 0.7, '#12344d', '#e8c887', 'rgba(201,160,78,.5)', true),
        ];
    }

    public static function count(): int
    {
        return \count(self::all());
    }

    public static function at(int $level): GameLevel
    {
        foreach (self::all() as $entry) {
            if ($entry->level === $level) {
                return $entry;
            }
        }

        return self::all()[0];
    }
}
