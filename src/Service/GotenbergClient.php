<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin wrapper around the Gotenberg (self-hosted, internal-network-only - see compose.yaml)
 * HTTP API operations the Livret Alternant PDF export needs. See App\Service\GotenbergUnavailableException
 * for the failure-handling contract callers rely on.
 */
class GotenbergClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $gotenbergUrl,
    ) {
    }

    /**
     * Converts one standalone HTML document to PDF bytes via headless Chromium, applying print
     * media rules the same way a browser's own "print to PDF" would (matches the behavior this
     * replaces).
     *
     * @return non-empty-string raw PDF bytes
     */
    public function convertHtmlToPdf(string $html): string
    {
        return $this->request('/forms/chromium/convert/html', new FormDataPart([
            'index.html' => new DataPart($html, 'index.html', 'text/html'),
            'emulatedMediaType' => 'print',
            'printBackground' => 'true',
            'preferCssPageSize' => 'true',
        ]));
    }

    /**
     * Merges ordered PDF byte-streams into one PDF.
     *
     * @param non-empty-list<string> $pdfs raw PDF bytes, in the desired final page order
     *
     * @return non-empty-string raw merged PDF bytes
     */
    public function mergePdfs(array $pdfs): string
    {
        if (1 === \count($pdfs)) {
            return $pdfs[0];
        }

        $fields = [];
        foreach ($pdfs as $index => $pdfBytes) {
            // Zero-padded filenames so Gotenberg's alphabetical merge order matches array order.
            $filename = sprintf('%04d.pdf', $index + 1);
            $fields[$filename] = new DataPart($pdfBytes, $filename, 'application/pdf');
        }

        return $this->request('/forms/pdfengines/merge', new FormDataPart($fields));
    }

    /**
     * How many pages a PDF byte-stream has, via Gotenberg's exiftool-backed metadata route.
     *
     * Used by App\Service\InternshipBookletPdfExporter to know how far an uploaded alternance
     * calendar pushes everything printed after it, since the booklet's page numbers (footers and
     * sommaire) are authored as fixed values rather than counted at render time.
     *
     * Falls back to 1 whenever the answer can't be read - an uncounted file then behaves exactly
     * like a single-page one, which is the pre-existing numbering, rather than failing the whole
     * export over a page number.
     */
    public function countPdfPages(string $pdfBytes): int
    {
        $json = $this->request('/forms/pdfengines/metadata/read', new FormDataPart([
            'file.pdf' => new DataPart($pdfBytes, 'file.pdf', 'application/pdf'),
        ]));

        // Keyed by filename: {"file.pdf": {..., "PageCount": 3}}.
        $metadata = json_decode($json, true);
        $pageCount = \is_array($metadata) ? (reset($metadata)['PageCount'] ?? null) : null;

        return \is_int($pageCount) && $pageCount > 0 ? $pageCount : 1;
    }

    private function request(string $path, FormDataPart $formData): string
    {
        try {
            $response = $this->httpClient->request('POST', rtrim($this->gotenbergUrl, '/').$path, [
                'headers' => $formData->getPreparedHeaders()->toArray(),
                'body' => $formData->bodyToIterable(),
                'timeout' => 30,
            ]);

            return $response->getContent();
        } catch (HttpClientExceptionInterface $exception) {
            throw new GotenbergUnavailableException(sprintf('Gotenberg request to "%s" failed.', $path), previous: $exception);
        }
    }
}
