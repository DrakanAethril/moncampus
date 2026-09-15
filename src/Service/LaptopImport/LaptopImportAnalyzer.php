<?php

declare(strict_types=1);

namespace App\Service\LaptopImport;

use App\Entity\Laptop;
use App\Enum\LaptopImportAction;
use App\Repository\LaptopRepository;

/**
 * Reads one parsed file against the inventory and says, line by line, what importing it would do.
 *
 * Writes nothing, and is run twice: once for the verification screen, once again from the same rows
 * just before the writing, because somebody may have added one of these machines in between.
 *
 * Two identifiers decide everything, and both are unique in the inventory (see App\Entity\Laptop):
 * the institution's inventory number and the manufacturer's serial number. The three cases are
 * therefore:
 *
 *  - neither is known        → **création**
 *  - both name the same row  → **déjà au parc**, left untouched
 *  - they disagree           → **bloquant**, and the whole file is refused
 *
 * The last one is the one worth spelling out: an inventory number already used by a machine with a
 * different serial number is either a typo or a number reused after a disposal, and a serial number
 * already used under a different inventory number is the same machine entered twice. Neither can be
 * settled by an import, so neither is guessed at.
 *
 * Comparisons are case-insensitive, because the database's own unique indexes are: MySQL's
 * `utf8mb4_*_ci` collation would refuse `5CD335G7GT` against `5cd335g7gt` at write time, so a file
 * holding both has to be told before it is written, not after.
 */
final class LaptopImportAnalyzer
{
    /**
     * What a fleet machine is worth on the convention when the file does not say. The same 500 € the
     * one-machine form offers (App\Form\LaptopType), and a default rather than a refusal: an
     * inventory list is a list of machines, and the sum is an institution's decision that the
     * operator corrects afterwards on the few rows it does not fit.
     */
    public const string DEFAULT_REPLACEMENT_VALUE = '500.00';

    public function __construct(private readonly LaptopRepository $laptops)
    {
    }

    /** @param list<LaptopRow> $rows */
    public function analyze(array $rows, string $fileName): LaptopImportAnalysis
    {
        $byAssetTag = $this->laptops->findByAssetTags(array_map(static fn (LaptopRow $row): string => $row->assetTag, $rows));
        $bySerialNumber = $this->laptops->findBySerialNumbers(array_map(static fn (LaptopRow $row): string => $row->serialNumber, $rows));

        $assetTagLines = $this->linesByKey($rows, static fn (LaptopRow $row): string => $row->assetTag);
        $serialLines = $this->linesByKey($rows, static fn (LaptopRow $row): string => $row->serialNumber);

        $analyzed = [];
        foreach ($rows as $row) {
            $analyzed[] = $this->analyzeRow($row, $byAssetTag, $bySerialNumber, $assetTagLines, $serialLines);
        }

        return new LaptopImportAnalysis($fileName, $analyzed);
    }

    /**
     * @param array<string, Laptop>    $byAssetTag
     * @param array<string, Laptop>    $bySerialNumber
     * @param array<string, list<int>> $assetTagLines
     * @param array<string, list<int>> $serialLines
     */
    private function analyzeRow(LaptopRow $row, array $byAssetTag, array $bySerialNumber, array $assetTagLines, array $serialLines): AnalyzedLaptop
    {
        $issues = [];

        if ('' === $row->assetTag) {
            $issues[] = ImportIssue::blocking('laptopImportMissingAssetTagIssue');
        }

        if ('' === $row->serialNumber) {
            $issues[] = ImportIssue::blocking('laptopImportMissingSerialNumberIssue');
        }

        foreach ($this->duplicateIssues($row, $assetTagLines, $serialLines) as $issue) {
            $issues[] = $issue;
        }

        [$replacementValue, $valueIssue] = $this->readReplacementValue($row);
        if (null !== $valueIssue) {
            $issues[] = $valueIssue;
        }

        if ('' === $row->deviceLabel()) {
            // Not an error: the convention has a "Marque / Type" box that would simply print empty,
            // and a machine with no model named is still a machine. Said so it can be filled in.
            $issues[] = ImportIssue::warning('laptopImportMissingDeviceIssue');
        }

        $existingByTag = $byAssetTag[self::key($row->assetTag)] ?? null;
        $existingBySerial = $bySerialNumber[self::key($row->serialNumber)] ?? null;

        $existingId = null;
        $action = LaptopImportAction::Create;

        if (null !== $existingByTag && $existingByTag === $existingBySerial) {
            $action = LaptopImportAction::Skip;
            $existingId = $existingByTag->getId();
            $issues[] = ImportIssue::note('laptopImportAlreadyInInventoryIssue');
        } else {
            if (null !== $existingByTag) {
                $issues[] = ImportIssue::blocking('laptopImportAssetTagTakenIssue', ['%serial%' => $existingByTag->getSerialNumber()]);
            }

            if (null !== $existingBySerial) {
                $issues[] = ImportIssue::blocking('laptopImportSerialNumberTakenIssue', ['%assetTag%' => $existingBySerial->getAssetTag()]);
            }
        }

        foreach ($issues as $issue) {
            if ($issue->isBlocking()) {
                $action = LaptopImportAction::Blocked;
                $existingId = null;

                break;
            }
        }

        return new AnalyzedLaptop($row, $action, $issues, $replacementValue, $existingId);
    }

    /**
     * The same number twice in one file is a mistake in the file, not in the inventory - and both
     * lines are named, because only the operator knows which of the two is the right one.
     *
     * @param array<string, list<int>> $assetTagLines
     * @param array<string, list<int>> $serialLines
     *
     * @return list<ImportIssue>
     */
    private function duplicateIssues(LaptopRow $row, array $assetTagLines, array $serialLines): array
    {
        $issues = [];

        $tagLines = $assetTagLines[self::key($row->assetTag)] ?? [];
        if ('' !== $row->assetTag && \count($tagLines) > 1) {
            $issues[] = ImportIssue::blocking('laptopImportDuplicateAssetTagIssue', ['%lines%' => implode(', ', $tagLines)]);
        }

        $serials = $serialLines[self::key($row->serialNumber)] ?? [];
        if ('' !== $row->serialNumber && \count($serials) > 1) {
            $issues[] = ImportIssue::blocking('laptopImportDuplicateSerialNumberIssue', ['%lines%' => implode(', ', $serials)]);
        }

        return $issues;
    }

    /**
     * The sum, as a decimal string, plus the finding that explains it.
     *
     * Reads what a French spreadsheet writes: a comma for the decimal point, spaces (ordinary and
     * non-breaking) as thousands separators, and a currency symbol the export drags along. Anything
     * left after that is not a sum and is refused rather than silently read as zero.
     *
     * @return array{string, ImportIssue|null}
     */
    private function readReplacementValue(LaptopRow $row): array
    {
        $raw = $row->replacementValue;

        if ('' === $raw) {
            return [self::DEFAULT_REPLACEMENT_VALUE, ImportIssue::note('laptopImportDefaultReplacementValueIssue', ['%value%' => self::DEFAULT_REPLACEMENT_VALUE])];
        }

        $normalized = str_replace([' ', "\u{202f}", "\u{00a0}", '€', 'EUR', ','], ['', '', '', '', '', '.'], $raw);

        if (!is_numeric($normalized) || (float) $normalized < 0) {
            return [self::DEFAULT_REPLACEMENT_VALUE, ImportIssue::blocking('laptopImportInvalidReplacementValueIssue', ['%value%' => $raw])];
        }

        return [number_format((float) $normalized, 2, '.', ''), null];
    }

    /**
     * Folded value => every line of the file carrying it. Blank values are left out: two empty cells
     * are not the same machine twice, they are two lines with nothing in them, each already
     * reported on its own.
     *
     * @param list<LaptopRow>            $rows
     * @param \Closure(LaptopRow): string $value
     *
     * @return array<string, list<int>>
     */
    private function linesByKey(array $rows, \Closure $value): array
    {
        $lines = [];
        foreach ($rows as $row) {
            $raw = $value($row);
            if ('' === $raw) {
                continue;
            }

            $lines[self::key($raw)][] = $row->line;
        }

        return $lines;
    }

    /** The spelling under which two values are the same value - see the class docblock. */
    private static function key(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
