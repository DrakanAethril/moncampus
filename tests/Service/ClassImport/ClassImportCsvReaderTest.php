<?php

declare(strict_types=1);

namespace App\Tests\Service\ClassImport;

use App\Service\ClassImport\ClassImportCsvReader;
use App\Service\ClassImport\ClassImportFileException;
use App\Service\ClassImport\StudentRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ClassImportCsvReaderTest extends TestCase
{
    private ClassImportCsvReader $reader;

    protected function setUp(): void
    {
        $this->reader = new ClassImportCsvReader();
    }

    public function testReadsTheThreeMandatoryColumns(): void
    {
        $rows = $this->reader->read("nom;prenom;mail\nDUPONT;Martin;martin@example.org\n");

        self::assertCount(1, $rows);
        self::assertSame('DUPONT', $rows[0]->lastname);
        self::assertSame('Martin', $rows[0]->firstname);
        self::assertSame('martin@example.org', $rows[0]->email);
        self::assertSame([], $rows[0]->freeCells);
    }

    // The header is line 1, so the first student is line 2 - every blocking message names a line
    // the secretary can find in their spreadsheet.
    public function testLineNumbersAreTheFileOwn(): void
    {
        $rows = $this->reader->read("nom;prenom;mail\nA;B;\nC;D;\n");

        self::assertSame([2, 3], array_map(static fn (StudentRow $row): int => $row->line, $rows));
    }

    public function testReadsCommaSeparatedFilesToo(): void
    {
        $rows = $this->reader->read("nom,prenom,mail\nDupont,Martin,martin@example.org");

        self::assertSame('Martin', $rows[0]->firstname);
    }

    public function testStripsTheBom(): void
    {
        $rows = $this->reader->read("\xEF\xBB\xBFnom;prenom;mail\nDupont;Martin;");

        self::assertSame('Dupont', $rows[0]->lastname);
    }

    public function testReadsWindows1252(): void
    {
        $content = (string) mb_convert_encoding("nom;prenom;mail\nMÜLLER;Zoé;z@example.org", 'Windows-1252', 'UTF-8');

        $rows = $this->reader->read($content);

        self::assertSame('MÜLLER', $rows[0]->lastname);
        self::assertSame('Zoé', $rows[0]->firstname);
    }

    #[DataProvider('headerAliases')]
    public function testAcceptsTheHeaderSpellingsASecretariatWrites(string $header): void
    {
        $rows = $this->reader->read($header."\nDupont;Martin;martin@example.org");

        self::assertSame('Dupont', $rows[0]->lastname);
        self::assertSame('Martin', $rows[0]->firstname);
        self::assertSame('martin@example.org', $rows[0]->email);
    }

    /** @return iterable<string, array{string}> */
    public static function headerAliases(): iterable
    {
        yield 'plain' => ['nom;prenom;mail'];
        yield 'accented and shouted' => ['NOM;Prénom;E-Mail'];
        yield 'padded' => ['nom ; prenom ; mail '];
        yield 'long spellings' => ['nom de famille;prénom;adresse mail'];
        yield 'courriel' => ['nom;prenom;courriel'];
        yield 'email' => ['nom;prenom;email'];
    }

    #[DataProvider('missingColumns')]
    public function testRefusesAFileMissingOneOfTheThreeColumns(string $header, string $expectedColumn): void
    {
        try {
            $this->reader->read($header."\na;b;c");
            self::fail('The file should have been refused.');
        } catch (ClassImportFileException $exception) {
            self::assertSame('classImportFileMissingColumnMessage', $exception->getMessageKey());
            self::assertSame(['%column%' => $expectedColumn], $exception->getParameters());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function missingColumns(): iterable
    {
        yield 'no lastname' => ['prenom;mail;option', 'nom'];
        yield 'no firstname' => ['nom;mail;option', 'prenom'];
        yield 'no mail' => ['nom;prenom;option', 'mail'];
    }

    public function testRefusesAnEmptyFile(): void
    {
        $this->expectException(ClassImportFileException::class);

        $this->reader->read('');
    }

    public function testRefusesAFileWithAHeaderAndNothingElse(): void
    {
        $this->expectException(ClassImportFileException::class);

        $this->reader->read('nom;prenom;mail');
    }

    public function testRefusesMoreThanThreeHundredStudents(): void
    {
        $content = "nom;prenom;mail\n".str_repeat("Dupont;Martin;\n", ClassImportCsvReader::MAX_ROWS + 1);

        try {
            $this->reader->read($content);
            self::fail('The file should have been refused.');
        } catch (ClassImportFileException $exception) {
            self::assertSame('classImportFileTooManyRowsMessage', $exception->getMessageKey());
        }
    }

    public function testAcceptsExactlyThreeHundredStudents(): void
    {
        $content = "nom;prenom;mail\n".str_repeat("Dupont;Martin;\n", ClassImportCsvReader::MAX_ROWS);

        self::assertCount(ClassImportCsvReader::MAX_ROWS, $this->reader->read($content));
    }

    // A spreadsheet keeps rows that only carry formatting; they are not an error, they are noise.
    public function testSkipsRowsWithNeitherNameNorFirstname(): void
    {
        $rows = $this->reader->read("nom;prenom;mail\n;;\nDupont;Martin;\n;;   \n");

        self::assertCount(1, $rows);
        self::assertSame(3, $rows[0]->line);
    }

    public function testKeepsARowMissingOnlyOneOfTheTwoNames(): void
    {
        $rows = $this->reader->read("nom;prenom;mail\nDupont;;\n");

        self::assertCount(1, $rows);
        self::assertSame('', $rows[0]->firstname);
    }

    public function testReadsFreeColumnsWithTheirHeader(): void
    {
        $rows = $this->reader->read("nom;prenom;mail;Option;Modalité\nDupont;Martin;;SLAM;Alternance\n");

        self::assertCount(2, $rows[0]->freeCells);
        self::assertSame('Option', $rows[0]->freeCells[0]->header);
        self::assertSame('option', $rows[0]->freeCells[0]->foldedHeader);
        self::assertSame('SLAM', $rows[0]->freeCells[0]->value);
        self::assertSame('modalite', $rows[0]->freeCells[1]->foldedHeader);
        self::assertSame('Alternance', $rows[0]->freeCells[1]->value);
    }

    // An export dragging empty columns out to BZ must not turn every line into a free cell.
    public function testIgnoresColumnsThatAreEmptyFromHeaderToLastRow(): void
    {
        $rows = $this->reader->read("nom;prenom;mail;;\nDupont;Martin;;;\n");

        self::assertSame([], $rows[0]->freeCells);
    }

    public function testKeepsAFreeColumnThatHasValuesButNoHeader(): void
    {
        $rows = $this->reader->read("nom;prenom;mail;\nDupont;Martin;;SLAM\n");

        self::assertCount(1, $rows[0]->freeCells);
        self::assertSame('', $rows[0]->freeCells[0]->foldedHeader);
        self::assertSame('SLAM', $rows[0]->freeCells[0]->value);
    }

    public function testFreeCellsAreTrimmedAndKeptEvenWhenEmpty(): void
    {
        $rows = $this->reader->read("nom;prenom;mail;option\nDupont;Martin;; SLAM \nDurand;Alice;;\n");

        self::assertSame('SLAM', $rows[0]->freeCells[0]->value);
        self::assertSame('', $rows[1]->freeCells[0]->value);
    }

    // A short row is what a spreadsheet writes when the trailing cells are empty.
    public function testToleratesRowsShorterThanTheHeader(): void
    {
        $rows = $this->reader->read("nom;prenom;mail;option\nDupont;Martin\n");

        self::assertSame('', $rows[0]->email);
        self::assertSame('', $rows[0]->freeCells[0]->value);
    }

    public function testTrimsTheThreeMandatoryCells(): void
    {
        $rows = $this->reader->read("nom;prenom;mail\n  Dupont ; Martin ; martin@example.org \n");

        self::assertSame('Dupont', $rows[0]->lastname);
        self::assertSame('Martin', $rows[0]->firstname);
        self::assertSame('martin@example.org', $rows[0]->email);
    }

    // Two columns spelled the same way: the first wins, the second becomes a free column, which
    // the analysis then refuses on its value rather than silently overwriting the address.
    public function testTheFirstSpellingOfAColumnWins(): void
    {
        $rows = $this->reader->read("nom;prenom;mail;courriel\nDupont;Martin;first@example.org;second@example.org\n");

        self::assertSame('first@example.org', $rows[0]->email);
        self::assertCount(1, $rows[0]->freeCells);
    }
}
