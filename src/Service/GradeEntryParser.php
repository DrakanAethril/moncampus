<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\GradeStatus;

/**
 * What a teacher typed into a gradebook cell, read as a status and a value.
 *
 * The grid accepts more than numbers: `abs`/`a` marks an absence, `ne`/`né`/`n.é.` a piece of work
 * that was not evaluated, `nt` one that was not tested at all, and a number in parentheses a grade
 * that counts for the student but is excluded from the class average. An empty cell erases the
 * grade rather than storing a zero - a blank and a nought are not the same statement about a
 * student, and the distinction is what the gradebook exists to keep.
 *
 * Extracted out of App\Controller\ProgramGradebookController: it is the rule the whole module is
 * built on, it holds no framework dependency, and it is the one part of that screen worth pinning
 * with unit tests rather than clicking through.
 */
final class GradeEntryParser
{
    /**
     * @param float $scale the evaluation's own maximum - a grade is clamped to it rather than
     *                     rejected, since a teacher typing 21 out of 20 means "full marks"
     *
     * @return array{0: ?GradeStatus, 1: ?float} status and value, both null when the cell is empty
     *                                           or holds nothing usable
     */
    public function parse(string $raw, float $scale): array
    {
        $trimmed = trim($raw);
        $lower = mb_strtolower($trimmed);

        if ('' === $trimmed) {
            return [null, null];
        }

        if ('abs' === $lower || 'a' === $lower) {
            return [GradeStatus::Absent, null];
        }

        if (\in_array($lower, ['ne', 'né', 'n.é.'], true)) {
            return [GradeStatus::NotEvaluated, null];
        }

        if ('nt' === $lower) {
            return [GradeStatus::NotTested, null];
        }

        if (1 === preg_match('/^\((.+)\)$/', $trimmed, $matches)) {
            $value = $this->clamp($matches[1], $scale);

            return null === $value ? [null, null] : [GradeStatus::Excluded, $value];
        }

        $value = $this->clamp($trimmed, $scale);

        return null === $value ? [null, null] : [GradeStatus::Normal, $value];
    }

    /**
     * A number as the grid accepts it: comma or dot as the decimal separator, bounded to [0, $max],
     * rounded to the two decimals the column displays. Null when it is not a number at all.
     */
    public function clamp(string $raw, float $max): ?float
    {
        $normalized = str_replace(',', '.', trim($raw));

        if (!is_numeric($normalized)) {
            return null;
        }

        return round(max(0.0, min($max, (float) $normalized)), 2);
    }
}
