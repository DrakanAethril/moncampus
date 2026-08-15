<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The page furniture of one HTML→PDF conversion: the running header/footer Chromium prints into the
 * page margins, and the margins themselves.
 *
 * It exists because those two things are inseparable. Chromium's print pipeline takes its margins as
 * conversion parameters and then IGNORES the document's own `@page { margin: … }`, so a document
 * that draws its own running band with `position: fixed` has nothing reserving a strip for it - the
 * band lands on top of the text, which is exactly what the progression export shipped with (the
 * footer repeated across the top of every page after the first). Handing Chromium a real footer and
 * a margin tall enough to hold it is the fix, and neither half works without the other.
 *
 * A header/footer document is standalone: it inherits no style from the main document, is clipped to
 * its margin band, and may use Chromium's own `pageNumber` / `totalPages` / `date` / `title` spans.
 *
 * Margins are written in CSS units ("14mm"), which Gotenberg parses since 8.4 - the compose file
 * pins 8.16.
 */
final readonly class GotenbergPageSetup
{
    public function __construct(
        private ?string $footerHtml = null,
        private ?string $headerHtml = null,
        private string $marginTop = '10mm',
        private string $marginBottom = '10mm',
        private string $marginLeft = '10mm',
        private string $marginRight = '10mm',
    ) {
    }

    /**
     * Gotenberg's plain form fields for this setup - the files (header.html/footer.html) are added
     * separately by the client, since they are parts rather than values.
     *
     * @return array<string, string>
     */
    public function fields(): array
    {
        return [
            'marginTop' => $this->marginTop,
            'marginBottom' => $this->marginBottom,
            'marginLeft' => $this->marginLeft,
            'marginRight' => $this->marginRight,
        ];
    }

    public function footerHtml(): ?string
    {
        return $this->footerHtml;
    }

    public function headerHtml(): ?string
    {
        return $this->headerHtml;
    }
}
