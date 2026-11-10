<?php

declare(strict_types=1);

namespace App\Service\SeatingPlan;

/**
 * The room as a seating plan draws it: a grid of cells seen from the students' side, the board
 * along the top edge (row 0 is the front), and the furniture on it.
 *
 * **A row is a rank of seats, not a row of the grid**: « devant » means the first two ranks that
 * actually hold a seat, whatever empty cells lie between the board and the first table. That is
 * what a teacher means by « le premier rang », and it keeps a rule true when the room is redrawn
 * one cell further back.
 */
final class SeatingRoom
{
    public const int MIN_COLUMNS = 6;
    public const int MAX_COLUMNS = 24;
    public const int MIN_ROWS = 4;
    public const int MAX_ROWS = 16;
    public const int MAX_TABLES = 120;
    public const int DEFAULT_COLUMNS = 16;
    public const int DEFAULT_ROWS = 11;

    /** @var list<SeatingSeat>|null */
    private ?array $seats = null;

    /** @var array<string, SeatingSeat>|null */
    private ?array $seatsById = null;

    /** @var array<string, int>|null */
    private ?array $ranks = null;

    /**
     * @param list<SeatingTable> $tables
     */
    public function __construct(
        public readonly int $columns,
        public readonly int $rows,
        public readonly array $tables,
    ) {
    }

    public static function empty(): self
    {
        return new self(self::DEFAULT_COLUMNS, self::DEFAULT_ROWS, []);
    }

    /** @return list<SeatingSeat> */
    public function seats(): array
    {
        if (null === $this->seats) {
            $this->seats = [];
            foreach ($this->tables as $table) {
                foreach ($table->seats() as $seat) {
                    $this->seats[] = $seat;
                }
            }
        }

        return $this->seats;
    }

    public function seat(string $id): ?SeatingSeat
    {
        if (null === $this->seatsById) {
            $this->seatsById = [];
            foreach ($this->seats() as $seat) {
                $this->seatsById[$seat->id] = $seat;
            }
        }

        return $this->seatsById[$id] ?? null;
    }

    /** 0 for the rank nearest the board. */
    public function rankOf(SeatingSeat $seat): int
    {
        if (null === $this->ranks) {
            $ys = array_values(array_unique(array_map(static fn (SeatingSeat $s): int => $s->y, $this->seats())));
            sort($ys);
            $this->ranks = [];
            foreach ($this->seats() as $s) {
                $this->ranks[$s->id] = (int) array_search($s->y, $ys, true);
            }
        }

        return $this->ranks[$seat->id] ?? 0;
    }

    /** The index of the last rank, -1 in a room without seats. */
    public function lastRank(): int
    {
        return \count(array_unique(array_map(static fn (SeatingSeat $s): int => $s->y, $this->seats()))) - 1;
    }

    /** Whether a piece fits inside the walls without covering another one (but $exceptId). */
    public function fits(SeatingTable $candidate, ?string $exceptId = null): bool
    {
        if ($candidate->x < 0 || $candidate->y < 0 || $candidate->x + $candidate->width() > $this->columns || $candidate->y + $candidate->height() > $this->rows) {
            return false;
        }

        $taken = [];
        foreach ($this->tables as $table) {
            if ($table->id !== $exceptId) {
                foreach ($table->cells() as $cell) {
                    $taken[$cell] = true;
                }
            }
        }

        foreach ($candidate->cells() as $cell) {
            if (isset($taken[$cell])) {
                return false;
            }
        }

        return true;
    }

    /** @return array{columns: int, rows: int, tables: list<array{id: string, type: string, x: int, y: int, rotated: bool}>} */
    public function toArray(): array
    {
        return [
            'columns' => $this->columns,
            'rows' => $this->rows,
            'tables' => array_map(static fn (SeatingTable $table): array => $table->toArray(), $this->tables),
        ];
    }
}
