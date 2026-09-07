<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipTutorLink;

/**
 * Builds the final Livret Alternant PDF for one InternshipTutorLink: renders
 * templates/internship/booklet.html.twig and converts it to PDF via Gotenberg.
 *
 * One extra step for each section of chapter II that is an uploaded PDF rather than HTML: the
 * alternance calendar when the Program says so (Program::$alternanceCalendarMode), and the emploi
 * du temps whenever the formation deposited one (Program::$timetableDocumentFileKey). Such a file
 * has to BE its section, and a PDF can't be poured into the HTML Chromium renders. So the booklet
 * is rendered as the slices around those sections and the files are merged in between - see the
 * `slice` note at the top of the template.
 *
 * The two are independent, which is why there are three cut points and not two: a formation may
 * have a generated calendar and an uploaded timetable, in which case the slice handed to Gotenberg
 * has to carry the calendar page and stop right after it.
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
        $timetableFileKey = $data['timetableFileKey'];

        if (null === $calendarFileKey && null === $timetableFileKey) {
            return $this->gotenbergClient->convertHtmlToPdf($renderView('internship/booklet.html.twig', $data));
        }

        $calendarPdf = null !== $calendarFileKey ? $this->fileUploadService->read($calendarFileKey) : null;
        $timetablePdf = null !== $timetableFileKey ? $this->fileUploadService->read($timetableFileKey) : null;

        // Everything printed after section II.1 carries a hardcoded page number, so the booklet has
        // to be told how many pages the calendar file actually adds beyond the single one the
        // generated calendar used to occupy, and how many the emploi du temps takes in full - that
        // section does not exist at all without it, so there is no page to discount.
        $extraPages = null !== $calendarPdf ? max(0, $this->gotenbergClient->countPdfPages($calendarPdf) - 1) : 0;
        $timetablePages = null !== $timetablePdf ? $this->gotenbergClient->countPdfPages($timetablePdf) : 0;

        $render = fn (string $slice): string => $this->gotenbergClient->convertHtmlToPdf($renderView(
            'internship/booklet.html.twig',
            $data + ['bookletSlice' => $slice, 'calendarExtraPages' => $extraPages, 'timetablePages' => $timetablePages],
        ));

        // The calendar page is rendered by the slice that precedes the first merged file: 'before'
        // stops short of it (the uploaded calendar takes its place), 'before-timetable' includes it
        // (the calendar is a grid, only the timetable is a file).
        $parts = null !== $calendarPdf
            ? [$render('before'), $calendarPdf]
            : [$render('before-timetable')];

        if (null !== $timetablePdf) {
            $parts[] = $timetablePdf;
        }

        $parts[] = $render('after');

        return $this->gotenbergClient->mergePdfs($parts);
    }

    /**
     * The same booklet cut down to one evaluation period: cover page, that period's own pages, back
     * cover. Never needs the merges the full export does - a partial export doesn't carry chapter II
     * at all - but it still counts the uploaded calendar's and emploi du temps' pages, because the
     * period pages print the page numbers they have in the complete document and those numbers move
     * with both.
     *
     * @param \Closure(string, array<string, mixed>): string $renderView bound to the calling
     *                                                                    controller's renderView()
     *
     * @return non-empty-string raw PDF bytes
     */
    public function exportPeriod(InternshipTutorLink $tutorLink, InternshipEvaluationPeriod $period, \Closure $renderView): string
    {
        $data = $this->bookletBuilder->build($tutorLink) + ['assetBaseUrl' => 'http://php'];
        $calendarFileKey = $data['calendarFileKey'];
        $timetableFileKey = $data['timetableFileKey'];
        $extraPages = null === $calendarFileKey
            ? 0
            : max(0, $this->gotenbergClient->countPdfPages($this->fileUploadService->read($calendarFileKey)) - 1);
        $timetablePages = null === $timetableFileKey
            ? 0
            : $this->gotenbergClient->countPdfPages($this->fileUploadService->read($timetableFileKey));

        return $this->gotenbergClient->convertHtmlToPdf($renderView(
            'internship/booklet.html.twig',
            $data + ['bookletPartialPeriodId' => $period->getId(), 'calendarExtraPages' => $extraPages, 'timetablePages' => $timetablePages],
        ));
    }
}
