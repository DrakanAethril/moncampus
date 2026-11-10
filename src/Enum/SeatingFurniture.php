<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a seating plan's room is furnished with (design/validated/plans-de-classe.md, §3).
 *
 * A piece covers whole cells of the room's grid, one cell being one seat: a double table is two
 * cells side by side, an island four. The teacher's desk covers cells and seats nobody - it is
 * drawn so that the plan reads like the room, and so that nothing is put where it stands.
 */
enum SeatingFurniture: string
{
    case Double = 'double';
    case Single = 'single';
    case Island = 'island';
    case Desk = 'desk';

    /** Width in cells, unrotated. */
    public function width(): int
    {
        return match ($this) {
            self::Single => 1,
            self::Double, self::Island, self::Desk => 2,
        };
    }

    /** Depth in cells, unrotated. */
    public function height(): int
    {
        return self::Island === $this ? 2 : 1;
    }

    public function seatCount(): int
    {
        return self::Desk === $this ? 0 : $this->width() * $this->height();
    }

    /** A square piece looks the same turned a quarter: rotating it is refused rather than stored. */
    public function canRotate(): bool
    {
        return $this->width() !== $this->height();
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Double => 'seatingFurnitureDoubleLabel',
            self::Single => 'seatingFurnitureSingleLabel',
            self::Island => 'seatingFurnitureIslandLabel',
            self::Desk => 'seatingFurnitureDeskLabel',
        };
    }
}
