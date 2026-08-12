<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\QuizImportImageValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * What may be deposited on the import screen. Server-side, for the same reason as
 * App\Service\PdfUploadValidator: `accept=` is a filter in a file dialog, not a control.
 */
class QuizImportImageValidatorTest extends TestCase
{
    private QuizImportImageValidator $validator;

    /** @var list<string> */
    private array $paths = [];

    protected function setUp(): void
    {
        $this->validator = new QuizImportImageValidator();
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
    }

    public function testARealPngPasses(): void
    {
        self::assertNull($this->validator->validate($this->file(self::png(), 'schema.png')));
    }

    /** The extension is a claim; the content is the fact. */
    public function testAFileThatOnlyClaimsToBeAnImageIsRefused(): void
    {
        self::assertSame('quizImportImageNotAnImageError', $this->validator->validate($this->file('BEGIN;DROP TABLE', 'schema.png')));
    }

    public function testAnOversizedImageIsRefused(): void
    {
        $file = $this->file(self::png().str_repeat('\0', QuizImportImageValidator::MAX_BYTES), 'gros.png');

        self::assertSame('quizImportImageTooLargeError', $this->validator->validate($file));
    }

    private function file(string $content, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'quizimg');
        self::assertIsString($path);
        file_put_contents($path, $content);
        $this->paths[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    /** The smallest valid PNG there is - one transparent pixel. */
    private static function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
    }
}
