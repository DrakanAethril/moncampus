<?php

namespace App\Service;

/**
 * One resolved cover-page/calendar slot from InternshipBookletBuilder::build() - either an image
 * (rendered inline via <img> in booklet.html.twig, $url set) or a PDF (merged as a real page by
 * the PDF-export flow via GotenbergClient::mergePdfs(), $url is null since it can't be embedded
 * via <img> - the export flow reads its bytes via FileUploadService::read($key) instead).
 */
final readonly class ProgramInfoAsset
{
    public function __construct(
        public string $key,
        public bool $isPdf,
        public ?string $url,
    ) {
    }
}
