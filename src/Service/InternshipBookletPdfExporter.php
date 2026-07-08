<?php

namespace App\Service;

use App\Entity\InternshipTutorLink;

/**
 * Builds the final Livret Alternant PDF for one InternshipTutorLink: the Chromium-rendered
 * booklet HTML (image-type cover/calendar are already embedded inline by
 * templates/internship/booklet.html.twig), with any PDF-type cover/calendar slot merged in as
 * real pages via Gotenberg's PDF-merge endpoint.
 *
 * Order: cover (if PDF) -> Chromium-rendered booklet -> calendar (if PDF). This mirrors exactly
 * where the *image*-type variant of each slot lands inside the Chromium render itself (cover
 * first, calendar last - see booklet.html.twig), so switching a Program between an image and a
 * PDF for the same slot never changes its position in the final document.
 */
class InternshipBookletPdfExporter
{
    public function __construct(
        private readonly InternshipBookletBuilder $bookletBuilder,
        private readonly GotenbergClient $gotenbergClient,
        private readonly FileUploadService $fileUploadService,
    ) {
    }

    /**
     * @param \Closure(string, array<string, mixed>): string $renderView bound to the calling
     *                                                                    controller's renderView()
     *
     * @return non-empty-string raw PDF bytes
     */
    public function export(InternshipTutorLink $tutorLink, \Closure $renderView): string
    {
        $data = $this->bookletBuilder->build($tutorLink);

        $html = $renderView('internship/booklet.html.twig', $data + ['assetBaseUrl' => 'http://php']);
        $mainPdf = $this->gotenbergClient->convertHtmlToPdf($html);

        $coverPage = $data['coverPage'] ?? null;
        $calendar = $data['calendar'] ?? null;

        $parts = [];
        if ($coverPage instanceof ProgramInfoAsset && $coverPage->isPdf) {
            $parts[] = $this->fileUploadService->read($coverPage->key);
        }
        $parts[] = $mainPdf;
        if ($calendar instanceof ProgramInfoAsset && $calendar->isPdf) {
            $parts[] = $this->fileUploadService->read($calendar->key);
        }

        return $this->gotenbergClient->mergePdfs($parts);
    }
}
