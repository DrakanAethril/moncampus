<?php

declare(strict_types=1);

namespace App\Service\LaptopImport;

use App\Enum\LaptopImportAction;

/**
 * The whole read-only verdict on one uploaded file: every line, plus the counts the operator
 * actually validates against ("34 créations, 28 déjà au parc, 0 bloquante").
 *
 * Built once by LaptopImportAnalyzer and built again from the same rows just before writing - see
 * LaptopImportExecutor, which refuses to run against an analysis that has moved since.
 */
final readonly class LaptopImportAnalysis
{
    /** @param list<AnalyzedLaptop> $laptops */
    public function __construct(
        public string $fileName,
        public array $laptops,
    ) {
    }

    /**
     * Everything has to be true at once: nothing blocking, and at least one line that actually
     * writes something. A file that would change nothing is not an import - the common case being
     * the same file uploaded twice, which must be idempotent and say so.
     */
    public function isImportable(): bool
    {
        return 0 === $this->blockingCount() && $this->createCount() > 0;
    }

    public function createCount(): int
    {
        return $this->countWith(LaptopImportAction::Create);
    }

    public function skipCount(): int
    {
        return $this->countWith(LaptopImportAction::Skip);
    }

    public function blockingCount(): int
    {
        return $this->countWith(LaptopImportAction::Blocked);
    }

    public function warningCount(): int
    {
        $count = 0;
        foreach ($this->laptops as $laptop) {
            $count += \count($laptop->warnings());
        }

        return $count;
    }

    /** @return list<AnalyzedLaptop> */
    public function laptopsWith(LaptopImportAction $action): array
    {
        return array_values(array_filter($this->laptops, static fn (AnalyzedLaptop $laptop): bool => $laptop->action === $action));
    }

    /** @return list<AnalyzedLaptop> */
    public function blockedLaptops(): array
    {
        return $this->laptopsWith(LaptopImportAction::Blocked);
    }

    /** @return list<AnalyzedLaptop> */
    public function laptopsWithWarnings(): array
    {
        return array_values(array_filter($this->laptops, static fn (AnalyzedLaptop $laptop): bool => [] !== $laptop->warnings()));
    }

    private function countWith(LaptopImportAction $action): int
    {
        return \count($this->laptopsWith($action));
    }
}
