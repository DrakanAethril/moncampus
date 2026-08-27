<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\GameAlias;
use App\Entity\User;

/**
 * One line of the class ranking.
 *
 * $student is carried so the screen can tell the reader *their own* line - and for nothing else.
 * What is drawn is the alias, and only the alias: during a period nobody learns who Lovelace is.
 */
final readonly class GameRankingRow
{
    public function __construct(
        public int $rank,
        public User $student,
        public ?GameAlias $alias,
        public int $index,
        public ?string $tier,
        public bool $isViewer,
    ) {
    }

    /** The patronym alone - short, neutral, and it fits in a column. */
    public function name(): string
    {
        return $this->alias?->getFigure()?->getSurname() ?? '—';
    }
}
