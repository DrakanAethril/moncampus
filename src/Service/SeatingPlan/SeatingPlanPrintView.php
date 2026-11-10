<?php

declare(strict_types=1);

namespace App\Service\SeatingPlan;

/**
 * The printed plan, laid out on the server: where each table and each name goes on the sheet, in
 * the students' view (the board on top) or the teacher's (turned half a turn, the board at the
 * bottom - the front row is then the one nearest the teacher holding the sheet).
 */
final class SeatingPlanPrintView
{
    public const int CELL = 58;

    /**
     * @param array<int, SeatingStudent> $roster
     *
     * @return array{width: int, height: int, fromDesk: bool, options: list<string>, tables: list<array{left: int, top: int, width: int, height: int, desk: bool}>, seats: list<array{left: int, top: int, size: int, firstName: string, lastName: string, optionIndex: ?int}>, seated: int}
     */
    public function layout(SeatingPlanDocument $document, array $roster, bool $fromDesk, bool $fullNames): array
    {
        $room = $document->room;
        $x = static fn (int $x, int $width): int => $fromDesk ? $room->columns - $x - $width : $x;
        $y = static fn (int $y, int $height): int => $fromDesk ? $room->rows - $y - $height : $y;

        $tables = [];
        foreach ($room->tables as $table) {
            $tables[] = [
                'left' => $x($table->x, $table->width()) * self::CELL + 4,
                'top' => $y($table->y, $table->height()) * self::CELL + 4,
                'width' => $table->width() * self::CELL - 8,
                'height' => $table->height() * self::CELL - 8,
                'desk' => 0 === $table->furniture->seatCount(),
            ];
        }

        // The options in alphabetical order, as the screen's legend gives them: the colour of a dot
        // is its option's rank in this list.
        $options = array_values(array_unique(array_filter(array_map(static fn (SeatingStudent $student): ?string => $student->option, $roster))));
        sort($options);

        $seats = [];
        $seated = 0;
        foreach ($room->seats() as $seat) {
            $student = isset($document->seats[$seat->id]) ? ($roster[$document->seats[$seat->id]] ?? null) : null;
            if (null !== $student) {
                ++$seated;
            }
            $seats[] = [
                'left' => $x($seat->x, 1) * self::CELL + 3,
                'top' => $y($seat->y, 1) * self::CELL + 3,
                'size' => self::CELL - 6,
                'firstName' => null === $student ? '' : $student->firstName,
                'lastName' => null === $student ? '' : ($fullNames || '' === $student->lastName ? $student->lastName : mb_substr($student->lastName, 0, 1).'.'),
                'optionIndex' => null === $student?->option ? null : ((int) array_search($student->option, $options, true)) % 4,
            ];
        }

        return [
            'width' => $room->columns * self::CELL,
            'height' => $room->rows * self::CELL,
            'fromDesk' => $fromDesk,
            'options' => $options,
            'tables' => $tables,
            'seats' => $seats,
            'seated' => $seated,
        ];
    }
}
