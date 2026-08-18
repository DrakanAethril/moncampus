<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\CsvTable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CsvTableTest extends TestCase
{
    public function testReadsSemicolonSeparatedRows(): void
    {
        $table = CsvTable::fromContent("nom;prenom\nDupont;Martin\n");

        self::assertSame([['nom', 'prenom'], ['Dupont', 'Martin']], $table->rows());
    }

    #[DataProvider('delimiters')]
    public function testDetectsTheDelimiterFromTheHeaderLine(string $delimiter): void
    {
        $content = implode($delimiter, ['nom', 'prenom', 'mail'])."\n".implode($delimiter, ['Dupont', 'Martin', 'm@x.fr']);

        self::assertSame([['nom', 'prenom', 'mail'], ['Dupont', 'Martin', 'm@x.fr']], CsvTable::fromContent($content)->rows());
    }

    /** @return iterable<string, array{string}> */
    public static function delimiters(): iterable
    {
        yield 'semicolon' => [';'];
        yield 'comma' => [','];
        yield 'tabulation' => ["\t"];
        yield 'pipe' => ['|'];
    }

    public function testStripsTheUtf8Bom(): void
    {
        $table = CsvTable::fromContent("\xEF\xBB\xBFnom;prenom\nDupont;Martin");

        self::assertSame('nom', $table->rows()[0][0]);
    }

    public function testRecoversWindows1252(): void
    {
        $content = (string) mb_convert_encoding("nom;prenom\nMÜLLER;Zoé", 'Windows-1252', 'UTF-8');

        self::assertSame(['MÜLLER', 'Zoé'], CsvTable::fromContent($content)->rows()[1]);
    }

    public function testKeepsQuotedCellsWhole(): void
    {
        $table = CsvTable::fromContent("nom;prenom\n\"Dupont;Durand\";Martin");

        self::assertSame(['Dupont;Durand', 'Martin'], $table->rows()[1]);
    }

    // A backslash is content here (a Windows path, a regex), never an escape - the same choice
    // App\Service\QuizCsvImporter made when this reading was still its own.
    public function testDoesNotTreatBackslashAsAnEscape(): void
    {
        $table = CsvTable::fromContent("a;b\n\"C:\\\";x");

        self::assertSame(['C:\\', 'x'], $table->rows()[1]);
    }

    public function testHandlesWindowsLineEndings(): void
    {
        $table = CsvTable::fromContent("nom;prenom\r\nDupont;Martin\r\n");

        self::assertSame([['nom', 'prenom'], ['Dupont', 'Martin']], $table->rows());
    }

    public function testAnEmptyContentReadsAsNoRows(): void
    {
        self::assertSame([], CsvTable::fromContent('')->rows());
        self::assertSame([], CsvTable::fromContent("  \n ")->rows());
    }

    public function testCellsAreAlwaysStrings(): void
    {
        // fgetcsv() hands back [null] for a blank line; nothing downstream should have to guard.
        $table = CsvTable::fromContent("nom;prenom\n\nDupont;Martin");

        self::assertSame([['nom', 'prenom'], [''], ['Dupont', 'Martin']], $table->rows());
    }

    #[DataProvider('headerSpellings')]
    public function testFold(string $raw, string $folded): void
    {
        self::assertSame($folded, CsvTable::fold($raw));
    }

    /** @return iterable<string, array{string, string}> */
    public static function headerSpellings(): iterable
    {
        yield 'plain' => ['prenom', 'prenom'];
        yield 'accented' => ['Prénom', 'prenom'];
        yield 'shouted' => ['PRENOM', 'prenom'];
        yield 'padded' => ['prenom ', 'prenom'];
        yield 'spaced' => ['nom de famille', 'nom_de_famille'];
        yield 'hyphenated' => ['e-mail', 'e_mail'];
        yield 'punctuated' => ['Adresse  mail.', 'adresse_mail'];
        yield 'empty' => ['', ''];
    }
}
