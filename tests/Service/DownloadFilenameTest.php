<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DownloadFilename;
use PHPUnit\Framework\TestCase;

class DownloadFilenameTest extends TestCase
{
    public function testKeepsAnOrdinaryNameAsItIs(): void
    {
        self::assertSame('Cours de gestion.pdf', DownloadFilename::sanitize('Cours de gestion.pdf'));
    }

    public function testKeepsAccentsAndSpaces(): void
    {
        // The whole point of the RFC 5987 half of the header: a French library is full of these,
        // and they are perfectly valid in a filename on every system this app is used from.
        self::assertSame('Résumé de séance n°3.docx', DownloadFilename::sanitize('Résumé de séance n°3.docx'));
    }

    public function testDropsAnythingThatWouldNameADirectory(): void
    {
        self::assertSame('passwd', DownloadFilename::sanitize('../../etc/passwd'));
        self::assertSame('notes.txt', DownloadFilename::sanitize('C:\\Users\\prof\\notes.txt'));
    }

    public function testDropsQuotesAndControlCharacters(): void
    {
        // A quote closes the header's own filename="..." early, and a CR/LF splits the header
        // altogether - which is why this runs on the way out rather than on the way in.
        self::assertSame('le cours.pdf', DownloadFilename::sanitize('le "cours".pdf'));
        self::assertSame('cours.pdf', DownloadFilename::sanitize("cours\r\n.pdf"));
        self::assertSame('cours.pdf', DownloadFilename::sanitize("cours\x00.pdf"));
    }

    public function testCollapsesWhitespaceAndTrims(): void
    {
        self::assertSame('mon cours.pdf', DownloadFilename::sanitize("  mon   cours.pdf \t"));
    }

    public function testANameMadeOnlyOfRefusedCharactersFallsBack(): void
    {
        self::assertSame('fichier', DownloadFilename::sanitize('///'));
        self::assertSame('fichier', DownloadFilename::sanitize(''));
        self::assertSame('fichier', DownloadFilename::sanitize('...'));
    }

    public function testTakesTheExtensionFromTheStoredObjectWhenTheNameHasNone(): void
    {
        // A node renamed in the library is a label, not a filename: « Mon cours » must still be
        // saved as something the machine can open.
        self::assertSame('Mon cours.pdf', DownloadFilename::sanitize('Mon cours', 'file-library/ab12.pdf'));
    }

    public function testDoesNotAddAnExtensionTheNameAlreadyHas(): void
    {
        self::assertSame('Mon cours.pdf', DownloadFilename::sanitize('Mon cours.pdf', 'file-library/ab12.pdf'));
        self::assertSame('Mon cours.PDF', DownloadFilename::sanitize('Mon cours.PDF', 'file-library/ab12.pdf'));
    }

    public function testKeepsTheNamesOwnExtensionOverTheObjects(): void
    {
        // Not a case to arbitrate: the display name is what the teacher typed, so it wins.
        self::assertSame('Mon cours.doc', DownloadFilename::sanitize('Mon cours.doc', 'file-library/ab12.pdf'));
    }

    public function testCapsTheLengthWithoutLosingTheExtension(): void
    {
        $name = str_repeat('é', 200).'.pdf';
        $sanitized = DownloadFilename::sanitize($name);

        self::assertLessThanOrEqual(120, \strlen($sanitized));
        self::assertStringEndsWith('.pdf', $sanitized);
        // Cut on a character boundary, never in the middle of a multi-byte one.
        self::assertSame($sanitized, mb_convert_encoding($sanitized, 'UTF-8', 'UTF-8'));
    }

    public function testHeaderCarriesBothTheAsciiFallbackAndTheUtf8Name(): void
    {
        self::assertSame(
            'attachment; filename="Resume de seance.docx"; filename*=UTF-8\'\'R%C3%A9sum%C3%A9%20de%20s%C3%A9ance.docx',
            DownloadFilename::header('attachment', 'Résumé de séance.docx'),
        );
    }

    public function testHeaderOmitsTheUtf8FormWhenTheNameIsAlreadyAscii(): void
    {
        self::assertSame('inline; filename="cours.pdf"', DownloadFilename::header('inline', 'cours.pdf'));
    }

    public function testAsciiFallbackDropsWhatTheFilesystemRefuses(): void
    {
        // « ° » and « – » have no ASCII form: the transliterator writes them as "?", which is itself
        // illegal in a filename on Windows. The real name still travels intact in filename*.
        self::assertSame(
            'attachment; filename="n3 - ete.pdf"; filename*=UTF-8\'\'n%C2%B03%20%E2%80%93%20%C3%A9t%C3%A9.pdf',
            DownloadFilename::header('attachment', 'n°3 – été.pdf'),
        );
    }

    public function testAsciiFallbackNeverEndsUpEmpty(): void
    {
        // A name with nothing transliterable in it at all: the fallback still has to be a filename.
        self::assertSame(
            'attachment; filename="fichier.pdf"; filename*=UTF-8\'\'%F0%9F%99%82.pdf',
            DownloadFilename::header('attachment', '🙂.pdf'),
        );
    }
}
