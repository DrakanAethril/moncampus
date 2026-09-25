<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PdfNamedDestinations;
use PHPUnit\Framework\TestCase;

/**
 * How the Livret learns on which page each of its headings landed: Chromium writes a named
 * destination for every anchor an internal link points at, and this reads them back.
 *
 * The fixture is a real Gotenberg/Chromium 132 output, not a hand-written PDF - the whole point is
 * to hold the reader to the structure Skia actually produces: an 11-page document, so the page tree
 * is nested (Skia groups pages by eight), and two anchors, one of them reached only through an empty
 * link in a hidden block, which is how the booklet measures anchors its sommaire does not list.
 */
class PdfNamedDestinationsTest extends TestCase
{
    public function testEachAnchorIsMappedToItsOneBasedPage(): void
    {
        $pdf = (string) file_get_contents(__DIR__.'/../Fixtures/chromium-named-destinations.pdf');

        self::assertSame(['section-i-5' => 3, 'section-period-2' => 9], PdfNamedDestinations::read($pdf));
    }

    public function testThePageCountIsReadFromTheSameTree(): void
    {
        $pdf = (string) file_get_contents(__DIR__.'/../Fixtures/chromium-named-destinations.pdf');

        self::assertSame(11, PdfNamedDestinations::pageCount($pdf));
    }

    /** No link in the document means no destination at all, which is an answer, not an error. */
    public function testADocumentWithoutDestinationsGivesAnEmptyMap(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj\n<</Type /Catalog\n/Pages 2 0 R>>\nendobj\n2 0 obj\n<</Type /Pages\n/Count 1\n/Kids [3 0 R]>>\nendobj\n3 0 obj\n<</Type /Page\n/Parent 2 0 R>>\nendobj\n%%EOF";

        self::assertSame([], PdfNamedDestinations::read($pdf));
        self::assertSame(1, PdfNamedDestinations::pageCount($pdf));
    }

    /** A name PDF escapes (#2D is "-") comes back as the id the HTML carried. */
    public function testEscapedNamesAreDecoded(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj\n<</Type /Catalog\n/Pages 2 0 R\n/Dests 4 0 R>>\nendobj\n2 0 obj\n<</Type /Pages\n/Count 1\n/Kids [3 0 R]>>\nendobj\n3 0 obj\n<</Type /Page\n/Parent 2 0 R>>\nendobj\n4 0 obj\n<</section#2Dii [3 0 R /XYZ 0 800 0]>>\nendobj\n%%EOF";

        self::assertSame(['section-ii' => 1], PdfNamedDestinations::read($pdf));
    }

    public function testSomethingThatIsNotAChromiumPdfIsRefused(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        PdfNamedDestinations::read('not a pdf');
    }
}
