<?php

declare(strict_types=1);

namespace App\Service\AlternanceImport;

use App\Enum\AlternanceImportAction;

/**
 * The whole read-only verdict on one uploaded file: every line, plus the aggregates the operator
 * actually validates against ("4 entreprises nouvelles, 12 tuteurs connus, 1 erreur").
 *
 * Built once by ImportAnalyzer and rebuilt from the same rows just before writing - see
 * ImportExecutor, which refuses to run against an analysis that is no longer clean.
 */
final readonly class ImportAnalysis
{
    /**
     * @param list<AnalyzedRow>  $rows
     * @param list<string>       $newEnterpriseNames  employers this import would create, deduplicated
     * @param list<string>       $knownEnterpriseNames
     * @param list<string>       $newTutorLabels      "Prénom NOM <mail>", deduplicated
     * @param list<string>       $knownTutorLabels
     * @param list<string>       $studentEmailLabels  students whose empty contact e-mail the file fills
     */
    public function __construct(
        public string $fileName,
        public array $rows,
        public array $newEnterpriseNames,
        public array $knownEnterpriseNames,
        public array $newTutorLabels,
        public array $knownTutorLabels,
        public array $studentEmailLabels = [],
    ) {
    }

    public function isImportable(): bool
    {
        return 0 === $this->blockingCount() && $this->createCount() > 0;
    }

    public function blockingCount(): int
    {
        return \count($this->rowsWithAction(AlternanceImportAction::Blocked));
    }

    public function createCount(): int
    {
        return \count($this->rowsWithAction(AlternanceImportAction::Create));
    }

    public function skipCount(): int
    {
        return \count($this->rowsWithAction(AlternanceImportAction::Skip));
    }

    public function warningCount(): int
    {
        $count = 0;
        foreach ($this->rows as $row) {
            $count += \count($row->warnings());
        }

        return $count;
    }

    /** @return list<AnalyzedRow> */
    public function blockedRows(): array
    {
        return $this->rowsWithAction(AlternanceImportAction::Blocked);
    }

    /** @return list<AnalyzedRow> */
    public function rowsWithAction(AlternanceImportAction $action): array
    {
        return array_values(array_filter($this->rows, static fn (AnalyzedRow $row): bool => $row->action === $action));
    }

    /** @return list<AnalyzedRow> */
    public function rowsWithWarnings(): array
    {
        return array_values(array_filter($this->rows, static fn (AnalyzedRow $row): bool => [] !== $row->warnings()));
    }
}
