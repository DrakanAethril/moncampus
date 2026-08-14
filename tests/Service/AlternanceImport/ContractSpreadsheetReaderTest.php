<?php

declare(strict_types=1);

namespace App\Tests\Service\AlternanceImport;

use App\Service\AlternanceImport\ContractSpreadsheetReader;
use App\Service\AlternanceImport\ImportFileException;
use App\Service\XlsxSheetReader;
use PHPUnit\Framework\TestCase;

/**
 * Reading the school's export into rows, over a fixture carrying the real column labels (typos,
 * trailing space and all) with fictitious people in them.
 *
 * The two that pay for themselves: "Tut. ent. 2 : Mail" must not claim the tutor's mail column -
 * the labels differ by one character - and a blank row left behind by Excel's formatting must not
 * become a contract with no student.
 */
class ContractSpreadsheetReaderTest extends TestCase
{
    private ContractSpreadsheetReader $reader;

    protected function setUp(): void
    {
        $this->reader = new ContractSpreadsheetReader(new XlsxSheetReader());
    }

    public function testReadsEveryContractLine(): void
    {
        $rows = $this->reader->read($this->fixture());

        self::assertCount(3, $rows);
        self::assertSame(2, $rows[0]->line);
        self::assertSame(4, $rows[2]->line);
    }

    public function testMapsEachColumnToItsField(): void
    {
        $row = $this->reader->read($this->fixture())[0];

        self::assertSame('BTSFICTIF1', $row->classCode);
        self::assertSame('MARTIN Camille', $row->studentName);
        self::assertSame('camille.martin@example.org', $row->studentEmail);
        self::assertSame('ATELIER DES SOURCES', $row->enterpriseName);
        self::assertStringContainsString('87000-LIMOGES', $row->enterpriseAddress);
        self::assertSame('DUPONT Léa', $row->tutorName);
        self::assertSame('lea.dupont@ateliersources.example', $row->tutorEmail);
        self::assertSame('01/09/2025 au 31/08/2026', $row->observations);
    }

    public function testPrefersTheMobileButFallsBackToTheLandline(): void
    {
        $rows = $this->reader->read($this->fixture());

        self::assertSame('05 00 00 00 02', $rows[0]->tutorBestPhone());
        self::assertSame('06 00 00 00 05', $rows[2]->tutorBestPhone());
    }

    public function testSkipsARowLeftBlank(): void
    {
        foreach ($this->reader->read($this->fixture()) as $row) {
            self::assertNotSame('', $row->studentName);
        }
    }

    public function testRefusesAFileItCannotOpen(): void
    {
        $this->expectException(ImportFileException::class);

        $this->reader->read(__FILE__);
    }

    private function fixture(): string
    {
        return \dirname(__DIR__, 2).'/Fixtures/alternance-contracts.xlsx';
    }
}
