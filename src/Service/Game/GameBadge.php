<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Program;
use App\Entity\User;

/**
 * What the main bar draws next to a name: the ring, the progress band and the displayed title
 * (design's screen 4, the handoff's `#3c` kept as it is).
 *
 * It exists as an object rather than a handful of Twig lookups because the bar is on every
 * authenticated page: one memoised read per request, or forty.
 */
final readonly class GameBadge
{
    public function __construct(
        public User $student,
        public Program $program,
        public GameLevelProgress $progress,
        public string $title,
        public string $initials,
        public ?string $avatarUrl = null,
    ) {
    }

    public function level(): int
    {
        return $this->progress->level->level;
    }

    /** 0-100, for the band's own width - the bar is CSS, so it takes a percentage and nothing else. */
    public function percent(): int
    {
        return (int) round($this->progress->progress * 100);
    }
}
