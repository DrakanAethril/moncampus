<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DocumentTextExtractor;
use App\Service\XlsxSheetReader;
use PHPUnit\Framework\TestCase;

/**
 * What the Claude connector can read of a teacher's file. The documents are built here, byte by
 * byte, rather than checked in: each one holds exactly the structure a test is about, and a reader
 * can see it.
 */
class DocumentTextExtractorTest extends TestCase
{
    private DocumentTextExtractor $extractor;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->extractor = new DocumentTextExtractor(new XlsxSheetReader());
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testReadsAWordDocumentWithItsHeadingsAndTables(): void
    {
        $body = '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Les VLAN</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t xml:space="preserve">Un VLAN </w:t></w:r><w:r><w:t>sépare les domaines.</w:t></w:r></w:p>'
            .'<w:tbl><w:tr><w:tc><w:p><w:r><w:t>ID</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Nom</w:t></w:r></w:p></w:tc></w:tr>'
            .'<w:tr><w:tc><w:p><w:r><w:t>10</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Admin</w:t></w:r></w:p></w:tc></w:tr></w:tbl>';
        $path = $this->zip('cours.docx', ['word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>']);

        $text = $this->extractor->extract($path, 'cours.docx')->text;

        self::assertSame("# Les VLAN\nUn VLAN sépare les domaines.\n| ID | Nom |\n| 10 | Admin |", $text);
    }

    public function testReadsSlidesInTheirNumberOrder(): void
    {
        $slide = static fn (string $text): string => '<?xml version="1.0"?><p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><p:cSld><p:spTree><p:sp><p:txBody><a:p><a:r><a:t>'.$text.'</a:t></a:r></a:p></p:txBody></p:sp></p:spTree></p:cSld></p:sld>';
        // Slide 10 is stored before slide 2: a name sort would put it second.
        $path = $this->zip('cours.pptx', ['ppt/slides/slide10.xml' => $slide('Dixième'), 'ppt/slides/slide2.xml' => $slide('Deuxième'), 'ppt/slides/slide1.xml' => $slide('Première')]);

        $text = (string) $this->extractor->extract($path, 'cours.pptx')->text;

        self::assertLessThan(strpos($text, 'Dixième'), strpos($text, 'Deuxième'));
        self::assertStringStartsWith("## Diapositive 1\nPremière", $text);
    }

    public function testReadsAnOpenDocumentText(): void
    {
        $path = $this->zip('cours.odt', ['content.xml' => '<?xml version="1.0"?><office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"><office:body><office:text><text:h text:outline-level="2">Trunk</text:h><text:p>802.1Q étiquette les trames.</text:p></office:text></office:body></office:document-content>']);

        self::assertSame("## Trunk\n802.1Q étiquette les trames.", $this->extractor->extract($path, 'cours.odt')->text);
    }

    public function testReadsAPdfPageByPage(): void
    {
        $path = $this->file('cours.pdf', $this->pdf(['Premiere page VLAN', 'Seconde page trunk']));

        $text = (string) $this->extractor->extract($path, 'cours.pdf')->text;

        self::assertStringContainsString("--- Page 1 ---\n\nPremiere page VLAN", $text);
        self::assertStringContainsString("--- Page 2 ---\n\nSeconde page trunk", $text);
    }

    public function testSaysSoWhenAPdfHoldsNoText(): void
    {
        $path = $this->file('scan.pdf', $this->pdf(['']));

        $document = $this->extractor->extract($path, 'scan.pdf');

        self::assertNull($document->text);
        self::assertStringContainsString('scanné', (string) $document->note);
    }

    public function testHtmlBecomesMarkdownWithoutItsScripts(): void
    {
        $path = $this->file('page.html', '<h2>Routage</h2><script>alert(1)</script><p>Une <strong>route</strong> statique.</p>');

        self::assertSame("## Routage\n\nUne **route** statique.", $this->extractor->extract($path, 'page.html')->text);
    }

    public function testAPictureIsHandedOverAsAPicture(): void
    {
        $path = $this->file('schema.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));

        $document = $this->extractor->extract($path, 'schema.png');

        self::assertSame('image/png', $document->imageMimeType);
        self::assertNull($document->text);
    }

    public function testNamesAFormatItCannotRead(): void
    {
        $document = $this->extractor->extract($this->file('video.mp4', 'x'), 'video.mp4');

        self::assertNull($document->text);
        self::assertStringContainsString('.mp4', (string) $document->note);
    }

    /**
     * The smallest PDF pdftotext accepts: one page per string, the text drawn in Helvetica.
     *
     * @param list<string> $pages
     */
    private function pdf(array $pages): string
    {
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', ''];
        $kids = [];
        foreach ($pages as $page) {
            $stream = '' === $page ? '' : \sprintf('BT /F1 12 Tf 72 720 Td (%s) Tj ET', $page);
            $objects[] = \sprintf('<< /Length %d >>stream'."\n".'%s'."\n".'endstream', \strlen($stream), $stream);
            $contentId = \count($objects);
            $objects[] = \sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents %d 0 R /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >> >>', $contentId);
            $kids[] = \count($objects).' 0 R';
        }
        $objects[1] = \sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $kids), \count($kids));

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = \strlen($pdf);
            $pdf .= \sprintf("%d 0 obj\n%s\nendobj\n", $index + 1, $object);
        }
        $xref = \strlen($pdf);
        $pdf .= \sprintf("xref\n0 %d\n0000000000 65535 f \n", \count($objects) + 1);
        foreach ($offsets as $offset) {
            $pdf .= \sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.\sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", \count($objects) + 1, $xref);
    }

    /**
     * @param array<string, string> $entries
     */
    private function zip(string $name, array $entries): string
    {
        $path = $this->file($name, '');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        foreach ($entries as $entry => $content) {
            $zip->addFromString($entry, $content);
        }
        $zip->close();

        return $path;
    }

    private function file(string $name, string $content): string
    {
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(6)).'-'.$name;
        file_put_contents($path, $content);
        $this->files[] = $path;

        return $path;
    }
}
