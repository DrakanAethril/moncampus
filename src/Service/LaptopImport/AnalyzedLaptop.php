<?php

declare(strict_types=1);

namespace App\Service\LaptopImport;

use App\Enum\LaptopImportAction;
use App\Enum\LaptopImportSeverity;

/**
 * One line of the file, read against the inventory: what would happen to it, why, and the values
 * the writing would actually use.
 *
 * $replacementValue is the *resolved* sum - the file's own, read as a decimal string, or the fleet
 * default when the file leaves the cell blank. The executor reads it here rather than re-parsing
 * the raw cell, so the sum the operator was shown on the verification screen is the sum that gets
 * written.
 */
final readonly class AnalyzedLaptop
{
    /** @param list<ImportIssue> $issues */
    public function __construct(
        public LaptopRow $row,
        public LaptopImportAction $action,
        public array $issues,
        public string $replacementValue,
        public ?int $existingId = null,
    ) {
    }

    public function line(): int
    {
        return $this->row->line;
    }

    /** @return list<ImportIssue> */
    public function blockingIssues(): array
    {
        return array_values(array_filter($this->issues, static fn (ImportIssue $issue): bool => $issue->isBlocking()));
    }

    /** @return list<ImportIssue> */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->issues,
            static fn (ImportIssue $issue): bool => LaptopImportSeverity::Warning === $issue->severity,
        ));
    }

    /** Whether this line would write anything at all. */
    public function writes(): bool
    {
        return LaptopImportAction::Create === $this->action;
    }
}
