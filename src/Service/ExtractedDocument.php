<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What App\Service\DocumentTextExtractor could read of a file: its text, or - for a picture - the
 * picture itself, or neither, with a sentence saying why.
 */
final readonly class ExtractedDocument
{
    private function __construct(
        public ?string $text,
        public ?string $imageBytes,
        public ?string $imageMimeType,
        public ?string $note,
    ) {
    }

    public static function text(string $text, ?string $note = null): self
    {
        return new self($text, null, null, $note);
    }

    public static function image(string $bytes, string $mimeType): self
    {
        return new self(null, $bytes, $mimeType, null);
    }

    public static function unreadable(string $note): self
    {
        return new self(null, null, null, $note);
    }
}
