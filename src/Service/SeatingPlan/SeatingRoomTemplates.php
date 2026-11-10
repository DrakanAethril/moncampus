<?php

declare(strict_types=1);

namespace App\Service\SeatingPlan;

use App\Enum\SeatingFurniture;

/**
 * The ready-made rooms a plan can start from (« Modèles »), all on the default 16 × 11 grid with
 * the desk at the front: rows of double tables, a U, islands, single tables for a test.
 */
final class SeatingRoomTemplates
{
    public const string ROWS = 'rows';
    public const string HORSESHOE = 'horseshoe';
    public const string ISLANDS = 'islands';
    public const string EXAM = 'exam';

    /** @return array<string, array{labelKey: string, descriptionKey: string, room: SeatingRoom}> */
    public function all(): array
    {
        return [
            self::ROWS => ['labelKey' => 'seatingTemplateRowsLabel', 'descriptionKey' => 'seatingTemplateRowsDescription', 'room' => $this->rows()],
            self::HORSESHOE => ['labelKey' => 'seatingTemplateHorseshoeLabel', 'descriptionKey' => 'seatingTemplateHorseshoeDescription', 'room' => $this->horseshoe()],
            self::ISLANDS => ['labelKey' => 'seatingTemplateIslandsLabel', 'descriptionKey' => 'seatingTemplateIslandsDescription', 'room' => $this->islands()],
            self::EXAM => ['labelKey' => 'seatingTemplateExamLabel', 'descriptionKey' => 'seatingTemplateExamDescription', 'room' => $this->exam()],
        ];
    }

    public function rows(): SeatingRoom
    {
        $tables = [$this->desk()];
        foreach ([2, 4, 6] as $y) {
            foreach ([1, 4, 10, 13] as $x) {
                $tables[] = $this->table(SeatingFurniture::Double, $x, $y, \count($tables));
            }
        }
        $tables[] = $this->table(SeatingFurniture::Double, 1, 8, \count($tables));
        $tables[] = $this->table(SeatingFurniture::Double, 13, 8, \count($tables));

        return $this->room($tables);
    }

    public function horseshoe(): SeatingRoom
    {
        $tables = [$this->desk()];
        foreach ([2, 4, 6, 8] as $y) {
            $tables[] = $this->table(SeatingFurniture::Double, 1, $y, \count($tables), true);
            $tables[] = $this->table(SeatingFurniture::Double, 14, $y, \count($tables), true);
        }
        foreach ([3, 5, 7, 9, 11] as $x) {
            $tables[] = $this->table(SeatingFurniture::Double, $x, 9, \count($tables));
        }

        return $this->room($tables);
    }

    public function islands(): SeatingRoom
    {
        $tables = [$this->desk()];
        foreach ([2, 6] as $y) {
            foreach ([2, 7, 12] as $x) {
                $tables[] = $this->table(SeatingFurniture::Island, $x, $y, \count($tables));
            }
        }

        return $this->room($tables);
    }

    public function exam(): SeatingRoom
    {
        $tables = [$this->desk()];
        foreach ([2, 4, 6, 8, 10] as $y) {
            foreach ([1, 4, 7, 10, 13] as $x) {
                $tables[] = $this->table(SeatingFurniture::Single, $x, $y, \count($tables));
            }
        }

        return $this->room($tables);
    }

    private function desk(): SeatingTable
    {
        return new SeatingTable('t0', SeatingFurniture::Desk, 7, 0);
    }

    private function table(SeatingFurniture $furniture, int $x, int $y, int $index, bool $rotated = false): SeatingTable
    {
        return new SeatingTable('t'.$index, $furniture, $x, $y, $rotated);
    }

    /** @param list<SeatingTable> $tables */
    private function room(array $tables): SeatingRoom
    {
        return new SeatingRoom(SeatingRoom::DEFAULT_COLUMNS, SeatingRoom::DEFAULT_ROWS, $tables);
    }
}
