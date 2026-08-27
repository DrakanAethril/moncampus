<?php

declare(strict_types=1);

namespace App\Service\Game;

/**
 * Where a student stands on the six-level board: the level reached, the next one when there is one,
 * and how far along the band between the two.
 *
 * $progress is what the main bar fills to (design's screen 4, « NIV. n » turned into a filled bar);
 * it is 1.0 on level 6, which has nothing after it and stays full for the rest of the cursus - a
 * legendary level that emptied its own bar would read as a demotion.
 */
final readonly class GameLevelProgress
{
    public function __construct(
        public GameLevel $level,
        public ?GameLevel $next,
        public int $xpTotal,
        public int $xpIntoLevel,
        public ?int $xpToNext,
        public float $progress,
    ) {
    }

    public function isMaxed(): bool
    {
        return null === $this->next;
    }
}
