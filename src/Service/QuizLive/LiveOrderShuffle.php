<?php

declare(strict_types=1);

namespace App\Service\QuizLive;

/**
 * The order a remise en ordre's pieces are shown in, on the board and on every phone: shuffled -
 * stored in the right order, they would spell the answer - and the same for the whole room, since
 * a shape means one piece for everybody. Seeded by the session and the question, so a reloaded
 * projector draws the very same order, and never the right one when there is any other.
 */
final class LiveOrderShuffle
{
    /**
     * @param list<int> $ids the pieces in the right order
     *
     * @return list<int>
     */
    public static function shown(array $ids, int $seed): array
    {
        if (\count($ids) < 2) {
            return $ids;
        }

        $shown = $ids;
        $state = $seed & 0x7FFFFFFF ?: 1;
        for ($i = \count($shown) - 1; $i > 0; --$i) {
            // A small LCG: mt_rand would reseed the whole worker's generator for everyone else.
            $state = (1103515245 * $state + 12345) & 0x7FFFFFFF;
            $j = $state % ($i + 1);
            [$shown[$i], $shown[$j]] = [$shown[$j], $shown[$i]];
        }

        // Shown in the right order would hand out the answer: rotate it one step instead.
        return $shown === $ids ? [...\array_slice($ids, 1), $ids[0]] : $shown;
    }
}
