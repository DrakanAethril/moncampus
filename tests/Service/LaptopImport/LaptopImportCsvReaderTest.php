<?php

declare(strict_types=1);

namespace App\Tests\Service\LaptopImport;

use App\Service\LaptopImport\LaptopImportCsvReader;
use App\Service\LaptopImport\LaptopImportFileException;
use App\Service\LaptopImport\LaptopRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LaptopImportCsvReaderTest extends TestCase
{
    private LaptopImportCsvReader $reader;

    protected function setUp(): void
    {
        $this->reader = new LaptopImportCsvReader();
    }

    /**
     * The school's own export, byte for byte: a BOM, CRLF line endings, semicolons, and headers
     * carrying a degree sign and an accent. It has to go in untouched - that is the whole point of
     * folding the headers rather than matching them.
     */
    public function testReadsTheSchoolExportAsItIsWritten(): void
    {
        $rows = $this->reader->read("\xEF\xBB\xBFN° inventaire;N° série;Marque;Modèle;Prix garantie\r\n2023/01;5CD335G7GT;HP;PAVILION;500\r\n");

        self::assertCount(1, $rows);
        self::assertSame('2023/01', $rows[0]->assetTag);
        self::assertSame('5CD335G7GT', $rows[0]->serialNumber);
        self::assertSame('HP', $rows[0]->brand);
        self::assertSame('PAVILION', $rows[0]->model);
        self::assertSame('500', $rows[0]->replacementValue);
    }

    /** The header is line 1, so the first machine is line 2 - every message names a line. */
    public function testLineNumbersAreTheFileOwn(): void
    {
        $rows = $this->reader->read("N° inventaire;N° série\n2023/01;AAA\n2023/02;BBB\n");

        self::assertSame([2, 3], array_map(static fn (LaptopRow $row): int => $row->line, $rows));
    }

    /** The optional columns are optional as columns, not only as cells. */
    public function testTheTwoRequiredColumnsAreEnough(): void
    {
        $rows = $this->reader->read("N° inventaire;N° série\n2023/01;AAA\n");

        self::assertSame('', $rows[0]->brand);
        self::assertSame('', $rows[0]->model);
        self::assertSame('', $rows[0]->replacementValue);
    }

    #[DataProvider('headerSpellings')]
    public function testHeaderSpellingsThatNameTheSameColumn(string $header): void
    {
        $rows = $this->reader->read($header."\n2023/01;AAA\n");

        self::assertSame('2023/01', $rows[0]->assetTag);
        self::assertSame('AAA', $rows[0]->serialNumber);
    }

    /** @return iterable<string, array{string}> */
    public static function headerSpellings(): iterable
    {
        yield 'the school export' => ['N° inventaire;N° série'];
        yield 'uppercase' => ['N° INVENTAIRE;N° SÉRIE'];
        yield 'unaccented' => ['numero inventaire;numero serie'];
        yield 'underscored' => ['numero_d_inventaire;numero_de_serie'];
        yield 'english' => ['asset tag;serial number'];
    }

    /** A spreadsheet writes rows that only carry formatting. They are noise, not an error. */
    public function testEntirelyEmptyLinesAreDropped(): void
    {
        $rows = $this->reader->read("N° inventaire;N° série\n2023/01;AAA\n;\n");

        self::assertCount(1, $rows);
    }

    /**
     * A line missing one of the two numbers is kept and answered for by the analysis, on its own
     * line - only a file missing the *column* is a file that was never about machines.
     */
    public function testALineMissingOneNumberIsStillRead(): void
    {
        $rows = $this->reader->read("N° inventaire;N° série\n2023/01;\n");

        self::assertCount(1, $rows);
        self::assertSame('', $rows[0]->serialNumber);
    }

    public function testAFileWithNoAssetTagColumnIsRefusedWhole(): void
    {
        $this->expectException(LaptopImportFileException::class);

        $this->reader->read("marque;N° série\nHP;AAA\n");
    }

    public function testAFileWithNoSerialColumnIsRefusedWhole(): void
    {
        $this->expectException(LaptopImportFileException::class);

        $this->reader->read("N° inventaire;marque\n2023/01;HP\n");
    }

    public function testAFileWithOnlyAHeaderIsRefusedWhole(): void
    {
        $this->expectException(LaptopImportFileException::class);

        $this->reader->read("N° inventaire;N° série\n");
    }

    public function testAFileLongerThanADeliveryIsRefusedWhole(): void
    {
        $lines = "N° inventaire;N° série\n";
        for ($index = 0; $index <= LaptopImportCsvReader::MAX_ROWS; ++$index) {
            $lines .= \sprintf("2023/%d;SERIAL%d\n", $index, $index);
        }

        $this->expectException(LaptopImportFileException::class);

        $this->reader->read($lines);
    }
}
