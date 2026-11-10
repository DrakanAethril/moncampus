<?php

declare(strict_types=1);

namespace App\Service\SeatingPlan;

use App\Enum\SeatingFurniture;

/**
 * A piece of furniture placed on the room's grid, by its top-left cell. Rotated means turned a
 * quarter: a double table then runs front to back.
 */
final readonly class SeatingTable
{
    public function __construct(
        public string $id,
        public SeatingFurniture $furniture,
        public int $x,
        public int $y,
        public bool $rotated = false,
    ) {
    }

    public function width(): int
    {
        return $this->rotated ? $this->furniture->height() : $this->furniture->width();
    }

    public function height(): int
    {
        return $this->rotated ? $this->furniture->width() : $this->furniture->height();
    }

    /** @return list<SeatingSeat> */
    public function seats(): array
    {
        if (0 === $this->furniture->seatCount()) {
            return [];
        }

        $seats = [];
        for ($j = 0; $j < $this->height(); ++$j) {
            for ($i = 0; $i < $this->width(); ++$i) {
                $seats[] = new SeatingSeat($this->id.'-'.($j * $this->width() + $i), $this->x + $i, $this->y + $j, $this->id);
            }
        }

        return $seats;
    }

    /** @return list<string> the cells it covers, as "x,y" */
    public function cells(): array
    {
        $cells = [];
        for ($j = 0; $j < $this->height(); ++$j) {
            for ($i = 0; $i < $this->width(); ++$i) {
                $cells[] = ($this->x + $i).','.($this->y + $j);
            }
        }

        return $cells;
    }

    /** @return array{id: string, type: string, x: int, y: int, rotated: bool} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'type' => $this->furniture->value, 'x' => $this->x, 'y' => $this->y, 'rotated' => $this->rotated];
    }
}
