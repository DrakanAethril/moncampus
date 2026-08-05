<?php

namespace App\Tests\Service;

use App\Service\PdfUploadValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * What the platform accepts as a PDF (design_handoff_workflow_postulation: offer descriptions, CVs
 * and cover letters).
 *
 * Worth pinning down because the browser-side `accept` attribute looks like it does this job and
 * does not: it filters a file dialog, and anything posting the form directly walks straight past
 * it. These are the checks that actually hold.
 */
class PdfUploadValidatorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/pdf-upload-validator-'.uniqid();
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function testNothingUploadedIsNotAnError(): void
    {
        // Whether a file is required is the caller's business: on a resend, only the refused piece
        // comes back and the others are deliberately absent.
        self::assertNull((new PdfUploadValidator())->validate(null));
    }

    public function testARealPdfPasses(): void
    {
        $file = $this->file('cv.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n%%EOF\n");

        self::assertNull((new PdfUploadValidator())->validate($file));
    }

    public function testAFileThatIsNotAPdfIsRefused(): void
    {
        $file = $this->file('cv.pdf', "Je ne suis pas un PDF, quoi qu'en dise mon nom.\n");

        self::assertSame('pdfUploadNotPdfError', (new PdfUploadValidator())->validate($file));
    }

    public function testTheContentDecidesNotTheDeclaredType(): void
    {
        // The client-sent Content-Type is whatever the sender chose to write, which is exactly why
        // it is not what gets checked.
        $file = $this->file('cv.pdf', "Toujours pas un PDF.\n", 'application/pdf');

        self::assertSame('pdfUploadNotPdfError', (new PdfUploadValidator())->validate($file));
    }

    public function testAPdfOverTheLimitIsRefused(): void
    {
        $padding = str_repeat('0', PdfUploadValidator::MAX_BYTES + 1);
        $file = $this->file('gros.pdf', "%PDF-1.4\n".$padding."\n%%EOF\n");

        self::assertSame('pdfUploadTooLargeError', (new PdfUploadValidator())->validate($file));
    }

    private function file(string $name, string $content, ?string $declaredMimeType = null): UploadedFile
    {
        $path = $this->directory.'/'.$name;
        file_put_contents($path, $content);

        // test: true, since nothing here came through a real HTTP upload.
        return new UploadedFile($path, $name, $declaredMimeType, null, true);
    }
}
