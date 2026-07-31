<?php

namespace App\Service;

use App\Entity\InternshipTutorLink;

/**
 * Builds the final Livret Alternant PDF for one InternshipTutorLink: renders
 * templates/internship/booklet.html.twig and converts it to PDF via Gotenberg.
 *
 * One extra step when the Program's alternance calendar is an uploaded PDF rather than the grid
 * generated from its periods (Program::$alternanceCalendarMode): that file has to BE section II.1
 * of the booklet, and a PDF can't be poured into the HTML Chromium renders. So the booklet is
 * rendered as the two slices around that section and the uploaded file is merged in between -
 * see the `slice` note at the top of the template.
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
        $data = $this->bookletBuilder->build($tutorLink) + ['assetBaseUrl' => 'http://php'];
        $calendarFileKey = $data['calendarFileKey'];

        if (null === $calendarFileKey) {
            return $this->gotenbergClient->convertHtmlToPdf($renderView('internship/booklet.html.twig', $data));
        }

        $calendarPdf = $this->fileUploadService->read($calendarFileKey);
        // Everything printed after section II.1 carries a hardcoded page number, so the booklet has
        // to be told how many pages the file actually adds beyond the single one the generated
        // calendar used to occupy.
        $extraPages = max(0, $this->gotenbergClient->countPdfPages($calendarPdf) - 1);

        $render = fn (string $slice): string => $this->gotenbergClient->convertHtmlToPdf($renderView(
            'internship/booklet.html.twig',
            $data + ['bookletSlice' => $slice, 'calendarExtraPages' => $extraPages],
        ));

        return $this->gotenbergClient->mergePdfs([$render('before'), $calendarPdf, $render('after')]);
    }
}
