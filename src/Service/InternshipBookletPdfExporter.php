<?php

namespace App\Service;

use App\Entity\InternshipTutorLink;

/**
 * Builds the final Livret Alternant PDF for one InternshipTutorLink: renders
 * templates/internship/booklet.html.twig and converts it to PDF via Gotenberg.
 */
class InternshipBookletPdfExporter
{
    public function __construct(
        private readonly InternshipBookletBuilder $bookletBuilder,
        private readonly GotenbergClient $gotenbergClient,
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

        return $this->gotenbergClient->convertHtmlToPdf($html);
    }
}
