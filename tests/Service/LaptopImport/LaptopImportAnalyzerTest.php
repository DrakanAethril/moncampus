<?php

declare(strict_types=1);

namespace App\Tests\Service\LaptopImport;

use App\Entity\Laptop;
use App\Enum\LaptopImportAction;
use App\Repository\LaptopRepository;
use App\Service\LaptopImport\LaptopImportAnalysis;
use App\Service\LaptopImport\LaptopImportAnalyzer;
use App\Service\LaptopImport\LaptopRow;
use PHPUnit\Framework\TestCase;

/**
 * What the import would do with each line, and why. The three cases the analyzer exists to tell
 * apart are a new machine, one the inventory already holds, and two numbers that disagree - the
 * last being the one that refuses the whole file rather than its own line.
 */
class LaptopImportAnalyzerTest extends TestCase
{
    public function testAnUnknownMachineIsCreated(): void
    {
        $analysis = $this->analyze([$this->row(2, '2026/01', 'AAA')], [], []);

        self::assertSame(LaptopImportAction::Create, $analysis->laptops[0]->action);
        self::assertTrue($analysis->isImportable());
    }

    /** Same inventory number, same serial number: this very machine, left untouched. */
    public function testAMachineAlreadyInTheInventoryIsSkipped(): void
    {
        $existing = $this->laptop('2026/01', 'AAA');

        $analysis = $this->analyze([$this->row(2, '2026/01', 'AAA')], ['2026/01' => $existing], ['aaa' => $existing]);

        self::assertSame(LaptopImportAction::Skip, $analysis->laptops[0]->action);
        // A file that would change nothing is not an import - re-uploading the same file must be
        // idempotent and say so.
        self::assertFalse($analysis->isImportable());
    }

    /** MySQL's unique indexes are case-insensitive, so the analysis has to be too. */
    public function testTheNumbersAreComparedWithoutRegardToCase(): void
    {
        $existing = $this->laptop('2026/01', 'AAA');

        $analysis = $this->analyze([$this->row(2, '2026/01', 'aaa')], ['2026/01' => $existing], ['aaa' => $existing]);

        self::assertSame(LaptopImportAction::Skip, $analysis->laptops[0]->action);
    }

    /** An inventory number already worn by another machine: a typo, or a number reused. */
    public function testAnAssetTagTakenByAnotherMachineBlocksTheFile(): void
    {
        $analysis = $this->analyze([$this->row(2, '2026/01', 'BBB')], ['2026/01' => $this->laptop('2026/01', 'AAA')], []);

        self::assertSame(LaptopImportAction::Blocked, $analysis->laptops[0]->action);
        self::assertFalse($analysis->isImportable());
    }

    /** The same machine entered twice under two inventory numbers. */
    public function testASerialNumberTakenUnderAnotherAssetTagBlocksTheFile(): void
    {
        $analysis = $this->analyze([$this->row(2, '2026/09', 'AAA')], [], ['aaa' => $this->laptop('2026/01', 'AAA')]);

        self::assertSame(LaptopImportAction::Blocked, $analysis->laptops[0]->action);
    }

    /** One blocking line refuses every other line of the file, not only its own. */
    public function testOneBlockingLineRefusesTheWholeFile(): void
    {
        $analysis = $this->analyze([
            $this->row(2, '2026/01', 'AAA'),
            $this->row(3, '', 'BBB'),
        ], [], []);

        self::assertSame(LaptopImportAction::Create, $analysis->laptops[0]->action);
        self::assertSame(LaptopImportAction::Blocked, $analysis->laptops[1]->action);
        self::assertFalse($analysis->isImportable());
    }

    public function testTheSameNumberTwiceInOneFileNamesBothLines(): void
    {
        $analysis = $this->analyze([
            $this->row(2, '2026/01', 'AAA'),
            $this->row(3, '2026/01', 'BBB'),
        ], [], []);

        self::assertSame(2, $analysis->blockingCount());
        self::assertSame('laptopImportDuplicateAssetTagIssue', $analysis->laptops[0]->blockingIssues()[0]->messageKey);
        self::assertSame(['%lines%' => '2, 3'], $analysis->laptops[0]->blockingIssues()[0]->parameters);
    }

    /** Two blank cells are two empty lines, not the same machine twice. */
    public function testBlankNumbersAreNotCountedAsDuplicatesOfEachOther(): void
    {
        $analysis = $this->analyze([
            $this->row(2, '', 'AAA'),
            $this->row(3, '', 'BBB'),
        ], [], []);

        foreach ($analysis->laptops as $laptop) {
            self::assertCount(1, $laptop->blockingIssues());
            self::assertSame('laptopImportMissingAssetTagIssue', $laptop->blockingIssues()[0]->messageKey);
        }
    }

    public function testABlankValueFallsBackToTheFleetDefault(): void
    {
        $analysis = $this->analyze([$this->row(2, '2026/01', 'AAA', value: '')], [], []);

        self::assertSame(LaptopImportAnalyzer::DEFAULT_REPLACEMENT_VALUE, $analysis->laptops[0]->replacementValue);
        self::assertSame(LaptopImportAction::Create, $analysis->laptops[0]->action);
    }

    /** What a French spreadsheet writes, read as the decimal string the column stores. */
    public function testAFrenchAmountIsRead(): void
    {
        $analysis = $this->analyze([$this->row(2, '2026/01', 'AAA', value: '1 200,50 €')], [], []);

        self::assertSame('1200.50', $analysis->laptops[0]->replacementValue);
    }

    public function testAnUnreadableAmountBlocksTheLine(): void
    {
        $analysis = $this->analyze([$this->row(2, '2026/01', 'AAA', value: 'à définir')], [], []);

        self::assertSame(LaptopImportAction::Blocked, $analysis->laptops[0]->action);
        self::assertSame('laptopImportInvalidReplacementValueIssue', $analysis->laptops[0]->blockingIssues()[0]->messageKey);
    }

    /** Nothing to print in the convention's "Marque / Type" box - said, never refused. */
    public function testANamelessMachineIsOnlyAWarning(): void
    {
        $analysis = $this->analyze([$this->row(2, '2026/01', 'AAA', brand: '', model: '')], [], []);

        self::assertSame(LaptopImportAction::Create, $analysis->laptops[0]->action);
        self::assertSame(1, $analysis->warningCount());
        self::assertTrue($analysis->isImportable());
    }

    /**
     * @param list<LaptopRow>       $rows
     * @param array<string, Laptop> $byAssetTag
     * @param array<string, Laptop> $bySerialNumber
     */
    private function analyze(array $rows, array $byAssetTag, array $bySerialNumber): LaptopImportAnalysis
    {
        // A stub, not a mock: the analysis is judged on what it decides, never on how many times it
        // asked the inventory.
        $repository = $this->createStub(LaptopRepository::class);
        $repository->method('findByAssetTags')->willReturn($byAssetTag);
        $repository->method('findBySerialNumbers')->willReturn($bySerialNumber);

        return (new LaptopImportAnalyzer($repository))->analyze($rows, 'inventaire.csv');
    }

    private function row(int $line, string $assetTag, string $serialNumber, string $brand = 'HP', string $model = 'PAVILION', string $value = '500'): LaptopRow
    {
        return new LaptopRow($line, $assetTag, $serialNumber, $brand, $model, $value);
    }

    private function laptop(string $assetTag, string $serialNumber): Laptop
    {
        return (new Laptop($assetTag))->setSerialNumber($serialNumber);
    }
}
