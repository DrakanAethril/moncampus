<?php

declare(strict_types=1);

namespace App\SeriousGame\Rules;

use App\Enum\SeriousGameFinish;

/**
 * The collection pieces a student holds in a game (design/validated/serious-games.md §8), as a pure
 * function of where they stand - what they hold is then only ever added to, and a finish only
 * raised (SeriousGameRewardGrant):
 *
 * - the **trophy** (L'Astrolabe) in brass once every unit is lit; silver once the final test reached
 *   `silver_at`, gold at `gold_at`;
 * - the **pin** with the trophy;
 * - one **card** per chapter whose units are all lit, and the final test's card once it was played
 *   to the end.
 *
 * Students only - the caller does not ask for an adult. No point, no ranking.
 */
final class RewardRules
{
    /**
     * @param array<string, array{0: int, 1: int}>                         $chapters  chapter => [lit, total]
     * @param array{code: string, silver_at: int, gold_at: int, ...}|null $trophy
     * @param array{code: string, ...}|null                                $pin
     *
     * @return array<string, SeriousGameFinish|null> reward code => finish (null for a piece that has none)
     */
    public static function earned(string $game, array $chapters, ?int $bestFinal, bool $finalPlayed, ?array $trophy, ?array $pin, string $finalCode): array
    {
        $earned = [];
        $allLit = [] !== $chapters;
        foreach ($chapters as $chapter => [$lit, $total]) {
            if ($total > 0 && $lit >= $total) {
                $earned[self::card($game, $chapter)] = null;
            } else {
                $allLit = false;
            }
        }

        if ($allLit && null !== $trophy) {
            $earned[$trophy['code']] = match (true) {
                null !== $bestFinal && $bestFinal >= $trophy['gold_at'] => SeriousGameFinish::Gold,
                null !== $bestFinal && $bestFinal >= $trophy['silver_at'] => SeriousGameFinish::Silver,
                default => SeriousGameFinish::Brass,
            };
            if (null !== $pin) {
                $earned[$pin['code']] = null;
            }
        }

        if ($finalPlayed) {
            $earned[self::card($game, $finalCode)] = null;
        }

        return $earned;
    }

    /**
     * The pieces of a game of the « scénario » mechanic (design/validated/serious-games-neko-con.md
     * §4): a card per act whose tickets were all closed at least once; the trophy in brass once the
     * final ticket was closed - the site online -, silver at `silver_at` tickets with three stars,
     * gold at `gold_at`, read on the best stars of each ticket; the pin with it.
     *
     * @param array<string, array{0: int, 1: int}>                         $chapters   act => [closed, total]
     * @param array{code: string, silver_at: int, gold_at: int, ...}|null $trophy
     * @param array{code: string, ...}|null                                $pin
     *
     * @return array<string, SeriousGameFinish|null>
     */
    public static function scenarioEarned(string $game, array $chapters, bool $online, int $threeStars, ?array $trophy, ?array $pin): array
    {
        $earned = [];
        foreach ($chapters as $chapter => [$closed, $total]) {
            if ($total > 0 && $closed >= $total) {
                $earned[self::card($game, $chapter)] = null;
            }
        }
        if ($online && null !== $trophy) {
            $earned[$trophy['code']] = match (true) {
                $threeStars >= $trophy['gold_at'] => SeriousGameFinish::Gold,
                $threeStars >= $trophy['silver_at'] => SeriousGameFinish::Silver,
                default => SeriousGameFinish::Brass,
            };
            if (null !== $pin) {
                $earned[$pin['code']] = null;
            }
        }

        return $earned;
    }

    public static function card(string $game, string $chapter): string
    {
        return 'card-'.$game.'-'.$chapter;
    }
}
