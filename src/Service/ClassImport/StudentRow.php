<?php

declare(strict_types=1);

namespace App\Service\ClassImport;

/**
 * One student as the file writes them - nothing resolved, nothing normalised, nothing checked
 * against the database. That is App\Service\ClassImport\ClassImportAnalyzer's work.
 *
 * `line` is the file's own line number (the header being line 1), so every message the operator
 * reads names a line they can find in their spreadsheet.
 */
final readonly class StudentRow
{
    /** @param list<FreeCell> $freeCells */
    public function __construct(
        public int $line,
        public string $lastname,
        public string $firstname,
        public string $email,
        public array $freeCells,
    ) {
    }
}
