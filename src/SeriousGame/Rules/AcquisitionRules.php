<?php

declare(strict_types=1);

namespace App\SeriousGame\Rules;

use App\Enum\SeriousGameBrightness;

/**
 * What a player acquires in a serious game (design/validated/serious-games.md §1, §6.2), as pure
 * functions - applied by the server alone, never by the game:
 *
 * - a unit is **lit** when its best series reaches 6 out of 12; its brightness is read on that best
 *   series (SeriousGameBrightness) and **never goes down**, neither with time nor with a worse series;
 * - a **comet** passes when the same typical error comes back twice among the player's last 30
 *   answers in the game - no time window - unless a comet for it is already in view; answers already
 *   counted by a comet that was solved do not count again;
 * - a comet is **élucidée** at 2 right out of 3; below, it simply stays in view;
 * - the game is **finished** when every unit is lit.
 *
 * Nothing here reads a date as a deadline: absence has no effect (§1.1).
 */
final class AcquisitionRules
{
    public const int LIT_AT = SeriousGameBrightness::LIT_AT;
    public const int COMET_WINDOW = 30;
    public const int COMET_REPEAT = 2;
    public const int COMET_SOLVED_AT = 2;

    public static function isLit(?int $best, int $litAt = self::LIT_AT): bool
    {
        return null !== $best && $best >= $litAt;
    }

    public static function best(?int $previous, int $score): int
    {
        return max($previous ?? 0, $score);
    }

    /** @param list<bool> $oks the answers of a series, right or not */
    public static function score(array $oks): int
    {
        return \count(array_filter($oks));
    }

    /**
     * @param list<array{misconception: string|null, at: int}> $answers  the player's answers in the game, oldest first
     * @param list<string>                                      $inView   the errors whose comet is in view
     * @param array<string, int>                                $solvedAt error => when its last comet was solved
     *
     * @return list<string> the errors whose comet passes now
     */
    public static function cometsToRaise(array $answers, array $inView, array $solvedAt, int $window = self::COMET_WINDOW, int $repeat = self::COMET_REPEAT): array
    {
        $counts = [];
        foreach (\array_slice($answers, -$window) as $answer) {
            $misconception = $answer['misconception'];
            if (null === $misconception || (isset($solvedAt[$misconception]) && $answer['at'] <= $solvedAt[$misconception])) {
                continue;
            }
            $counts[$misconception] = ($counts[$misconception] ?? 0) + 1;
        }

        $raised = [];
        foreach ($counts as $misconception => $count) {
            if ($count >= $repeat && !\in_array($misconception, $inView, true)) {
                $raised[] = (string) $misconception;
            }
        }

        return $raised;
    }

    /** @param list<bool> $oks the three answers of a comet's study */
    public static function cometSolved(array $oks, int $solvedAt = self::COMET_SOLVED_AT): bool
    {
        return self::score($oks) >= $solvedAt;
    }

    public static function finished(int $lit, int $total): bool
    {
        return $total > 0 && $lit >= $total;
    }
}
