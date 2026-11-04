<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

/**
 * « L'écart de cote sur 14 jours » of a standings line (design/validated/quiz-tournament.md §6.2), as
 * a pure function: a rating only ever moves through a finished duel, so what it moved over a window is
 * the sum of what each duel of that window moved - the duel keeps both ratings before and after
 * (§6.1). A duel without its ratings after (still running) moves nothing.
 */
final class QuizTournamentRatingTrend
{
    /**
     * @param list<array{a: int, b: int, beforeA: int, afterA: int|null, beforeB: int, afterB: int|null}> $duels
     *
     * @return array<int, int> user id => rating difference
     */
    public static function deltas(array $duels): array
    {
        $deltas = [];
        foreach ($duels as $duel) {
            $deltas[$duel['a']] = ($deltas[$duel['a']] ?? 0) + (null === $duel['afterA'] ? 0 : $duel['afterA'] - $duel['beforeA']);
            $deltas[$duel['b']] = ($deltas[$duel['b']] ?? 0) + (null === $duel['afterB'] ? 0 : $duel['afterB'] - $duel['beforeB']);
        }

        return $deltas;
    }
}
