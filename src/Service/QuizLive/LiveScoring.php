<?php

declare(strict_types=1);

namespace App\Service\QuizLive;

/**
 * Kahoot-style speed bonus: a right answer given at once is worth about 1000 points, one given at
 * the buzzer about 500, a wrong or missing one nothing. Read on server timestamps only - never a
 * client-reported elapsed time.
 *
 * Right is right whatever the type: a QCM multiple is all its right propositions and none other, an
 * order is the whole order, a number is inside its margin (the user's decision of 2026-11-05 - no
 * partial points, as everywhere else on the platform).
 */
final class LiveScoring
{
    /**
     * The 20 s floor is for an untimed question: the speed bonus is the whole point of the board,
     * so an unlimited question still rewards answering fast rather than handing everyone the same
     * 1000 points.
     */
    public const int UNTIMED_REFERENCE_SECONDS = 20;

    public static function points(bool $correct, float $elapsedSeconds, ?int $questionSeconds): int
    {
        if (!$correct) {
            return 0;
        }

        $seconds = $questionSeconds ?? self::UNTIMED_REFERENCE_SECONDS;
        $remaining = max(0.0, $seconds - max(0.0, $elapsedSeconds));

        return (int) round(500 + 500 * min(1.0, $remaining / $seconds));
    }
}
