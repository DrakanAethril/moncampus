<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Names a saved lot of groups (App\Entity\GroupBatch) so that no two of one teacher's lots on one
 * Program are spelled the same.
 *
 * The banner of « Mes groupes » is nothing but those names: two identical chips are two chips
 * nobody can tell apart, and clicking either is a coin toss. The tool used to keep them distinct
 * by making a re-save under an existing name *replace* that lot - which is exactly what « Dupliquer »
 * cannot do, since the whole point of the button is to end up with a second row. So the copy gets
 * a name instead: « TP réseau », then « TP réseau (2) ».
 *
 * Comparison is case- and space-insensitive on purpose: « TP Réseau » and « tp réseau » read as the
 * same chip on screen, and the rule exists for the screen rather than for the column.
 */
class GroupBatchNaming
{
    /**
     * @param list<string> $takenNames the names already in use - when renaming an existing lot,
     *                                 that lot's own name must be left out, or it would be pushed
     *                                 to « (2) » by itself
     */
    public function unique(string $desired, array $takenNames): string
    {
        $desired = trim($desired);

        $taken = [];
        foreach ($takenNames as $name) {
            $taken[$this->fold($name)] = true;
        }

        if (!isset($taken[$this->fold($desired)])) {
            return $desired;
        }

        // From 2 rather than from 1: the unsuffixed name *is* the first one. A gap left by a
        // deleted lot is taken back rather than skipped - the number is a disambiguator, not a
        // count of how many copies were ever made.
        $suffix = 2;
        while (isset($taken[$this->fold(\sprintf('%s (%d)', $desired, $suffix))])) {
            ++$suffix;
        }

        return \sprintf('%s (%d)', $desired, $suffix);
    }

    private function fold(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
