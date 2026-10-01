<?php

declare(strict_types=1);

namespace App\Service\EnterprisePool\Import;

/** The whole verdict of « Importer l'historique » - nothing is written until it is confirmed. */
final readonly class HostingImportAnalysis
{
    /** @param list<HostingImportLine> $lines */
    public function __construct(
        public array $lines,
    ) {
    }

    public function blockingCount(): int
    {
        return \count(array_filter($this->lines, static fn (HostingImportLine $line): bool => $line->isBlocking()));
    }

    public function importableCount(): int
    {
        return \count(array_filter($this->lines, static fn (HostingImportLine $line): bool => !$line->isBlocking() && !$line->skipped));
    }

    public function skippedCount(): int
    {
        return \count(array_filter($this->lines, static fn (HostingImportLine $line): bool => $line->skipped && !$line->isBlocking()));
    }

    /** How many companies the import would create - one per distinct new company of the file. */
    public function newEnterpriseCount(): int
    {
        $keys = [];
        foreach ($this->lines as $line) {
            if (!$line->isBlocking() && !$line->skipped && null === $line->enterprise) {
                $keys[$line->enterpriseKey()] = true;
            }
        }

        return \count($keys);
    }

    public function isImportable(): bool
    {
        return 0 === $this->blockingCount() && $this->importableCount() > 0;
    }
}
