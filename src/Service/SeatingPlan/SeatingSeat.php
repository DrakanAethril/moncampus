<?php

declare(strict_types=1);

namespace App\Service\SeatingPlan;

/**
 * One place in the room: one cell of a table. Its id is its table's and its rank inside it
 * (`t4-1`), so that moving a table moves its seats and the students sitting on them.
 */
final readonly class SeatingSeat
{
    public function __construct(
        public string $id,
        public int $x,
        public int $y,
        public string $tableId,
    ) {
    }

    /**
     * Next to each other in any direction, diagonals and front/back included.
     *
     * Two cells front to back, one sideways: a room drawn on this grid leaves a row for the chairs
     * between two ranks of tables, so the student right behind sits two cells away - and whoever
     * sits behind can talk, pass a note or read the copy in front of them. Further back, across a
     * real aisle, is no longer next to anybody.
     */
    public function isNear(self $other): bool
    {
        return abs($this->x - $other->x) <= 1 && abs($this->y - $other->y) <= 2;
    }

    /** Side by side: the same table, or two touching cells of one row. */
    public function isBeside(self $other): bool
    {
        return $this->tableId === $other->tableId || ($this->y === $other->y && 1 === abs($this->x - $other->x));
    }

    public function distanceTo(self $other): int
    {
        return abs($this->x - $other->x) + abs($this->y - $other->y);
    }
}
