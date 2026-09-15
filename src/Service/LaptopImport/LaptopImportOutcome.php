<?php

declare(strict_types=1);

namespace App\Service\LaptopImport;

/**
 * What one confirmed import actually wrote - read once by the recap screen, then dropped.
 *
 * Inventory numbers rather than ids: the recap is read next to the spreadsheet the file came from,
 * and that is the only identifier both of them share.
 */
final readonly class LaptopImportOutcome
{
    /**
     * @param list<string> $createdAssetTags
     * @param list<string> $skippedAssetTags
     */
    public function __construct(
        public array $createdAssetTags,
        public array $skippedAssetTags,
    ) {
    }

    public function createdCount(): int
    {
        return \count($this->createdAssetTags);
    }
}
