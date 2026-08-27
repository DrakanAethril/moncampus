<?php

declare(strict_types=1);

namespace App\Service\Game;

/**
 * The class ranking, and the two counts the screen prints beside it.
 *
 * $discreetCount is shown on purpose: a ranking of twenty-eight in a class of thirty has to say
 * where the other two went, or the ranking reads as broken. It never says *who*.
 *
 * There is one tab and it is **the class**. No « entre promos », no section ranking, no comparison
 * between filières: the frontier of a formation is crossed nowhere in this application (§4,
 * decision 1).
 */
final readonly class GameRankingView
{
    /** @param list<GameRankingRow> $rows */
    public function __construct(
        public array $rows,
        public int $discreetCount,
        public ?GameRankingRow $viewerRow = null,
    ) {
    }

    public function count(): int
    {
        return \count($this->rows);
    }
}
