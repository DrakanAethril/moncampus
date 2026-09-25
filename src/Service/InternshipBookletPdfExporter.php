<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipTutorLink;

/**
 * Builds the final Livret Alternant PDF for one InternshipTutorLink: renders
 * templates/internship/booklet.html.twig and converts it to PDF via Gotenberg.
 *
 * No page number is known in advance - a section can run over several sheets, and two sections of
 * chapter II can be uploaded PDFs of any length - so the booklet is printed, measured, and printed
 * again:
 *
 *  - the running footer is Chromium's own counter(page), right by construction;
 *  - the sommaire is not (Chromium has no target-counter()), so a first print is read back for the
 *    page of every anchor (App\Service\PdfNamedDestinations) and the next print writes them in. The
 *    sommaire is a single page whatever its numbers, so the second print lands every heading where
 *    the first did; the loop checks that rather than assuming it;
 *  - an uploaded calendar or emploi du temps cannot be poured into HTML, so the booklet is printed
 *    as the part before them and the part after them, and the files are merged in between. The
 *    'after' part is told how many pages precede it, so its counter continues the booklet's.
 *
 * Why parts and not one print with blank pages overwritten afterwards: that needs Gotenberg to cut
 * a PDF, and its split route (8.16, splitUnify) returns a damaged file about one time in two on
 * this document - measured, not guessed.
 */
class InternshipBookletPdfExporter
{
    /** Two prints suffice; the third only exists so a layout that moves cannot loop forever. */
    private const int MAX_PRINTS = 3;

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
        return $this->printBooklet($tutorLink, $renderView)['pdf'];
    }

    /**
     * The same booklet cut down to one evaluation period: cover page, that period's own pages, back
     * cover. Its pages carry the numbers they have in the complete booklet - an extract and the
     * complete document name the same page for the same content, which is what the follow-up visit
     * relies on when both are on the table - so the complete booklet is measured first.
     *
     * @param \Closure(string, array<string, mixed>): string $renderView bound to the calling
     *                                                                    controller's renderView()
     *
     * @return non-empty-string raw PDF bytes
     */
    public function exportPeriod(InternshipTutorLink $tutorLink, InternshipEvaluationPeriod $period, \Closure $renderView): string
    {
        $booklet = $this->printBooklet($tutorLink, $renderView);

        // Period anchors are numbered by position in the booklet (section-period-1, -2, ...).
        $position = array_search($period->getId(), $booklet['periodIds'], true);
        if (false === $position) {
            throw new \InvalidArgumentException('This evaluation period is not part of the booklet.');
        }
        $firstPage = $booklet['pages']['section-period-'.($position + 1)] ?? throw new \UnexpectedValueException('The period was not found in the printed booklet.');

        // The cover takes one number before the period's first page, unprinted.
        return $this->print($booklet['data'], $renderView, [
            'bookletSlice' => 'period',
            'bookletPartialPeriodId' => $period->getId(),
            'pageOffset' => $firstPage - 2,
        ]);
    }

    /**
     * @param \Closure(string, array<string, mixed>): string $renderView
     *
     * @return array{pdf: non-empty-string, pages: array<string, int>, periodIds: list<int|null>, data: array<string, mixed>}
     */
    private function printBooklet(InternshipTutorLink $tutorLink, \Closure $renderView): array
    {
        $data = $this->bookletBuilder->build($tutorLink) + ['assetBaseUrl' => 'http://php', 'pdfExport' => true];

        /** @var list<array{period: InternshipEvaluationPeriod}> $periods */
        $periods = \is_array($data['periods'] ?? null) ? $data['periods'] : [];
        $periodIds = array_map(static fn (array $entry): ?int => $entry['period']->getId(), $periods);

        $calendarPdf = \is_string($data['calendarFileKey'] ?? null) ? $this->fileUploadService->read($data['calendarFileKey']) : null;
        $timetablePdf = \is_string($data['timetableFileKey'] ?? null) ? $this->fileUploadService->read($data['timetableFileKey']) : null;

        if (null === $calendarPdf && null === $timetablePdf) {
            $tocPages = [];
            for ($printed = 1;; ++$printed) {
                $pdf = $this->print($data, $renderView, ['tocPages' => $tocPages]);
                $pages = PdfNamedDestinations::read($pdf);
                if ($pages === $tocPages || self::MAX_PRINTS === $printed) {
                    break;
                }
                $tocPages = $pages;
            }

            return ['pdf' => $pdf, 'pages' => $pages, 'periodIds' => $periodIds, 'data' => $data];
        }

        // The 'after' part depends only on how many pages come before it, so it is printed again
        // only when that number moves.
        $after = null;
        $afterOffset = null;
        $afterPages = [];
        $tocPages = [];
        for ($printed = 1;; ++$printed) {
            $before = $this->print($data, $renderView, ['bookletSlice' => 'before', 'tocPages' => $tocPages]);
            $pages = PdfNamedDestinations::read($before);
            $offset = PdfNamedDestinations::pageCount($before);

            // The sections an uploaded file IS start on its first page.
            if (null !== $calendarPdf) {
                $pages['section-ii'] = $pages['section-ii-1'] = $offset + 1;
                $offset += $this->gotenbergClient->countPdfPages($calendarPdf);
            }
            if (null !== $timetablePdf) {
                $pages['section-ii-2'] = $offset + 1;
                $offset += $this->gotenbergClient->countPdfPages($timetablePdf);
            }

            if ($offset !== $afterOffset) {
                $after = $this->print($data, $renderView, ['bookletSlice' => 'after', 'pageOffset' => $offset]);
                $afterPages = array_map(static fn (int $page): int => $page + $offset, PdfNamedDestinations::read($after));
                $afterOffset = $offset;
            }
            $pages += $afterPages;

            if ($pages === $tocPages || self::MAX_PRINTS === $printed) {
                break;
            }
            $tocPages = $pages;
        }

        $pdf = $this->gotenbergClient->mergePdfs(array_values(array_filter([$before, $calendarPdf, $timetablePdf, $after])));

        return ['pdf' => $pdf, 'pages' => $pages, 'periodIds' => $periodIds, 'data' => $data];
    }

    /**
     * @param array<string, mixed>                           $data
     * @param \Closure(string, array<string, mixed>): string $renderView
     * @param array<string, mixed>                           $part       which part, and how it is numbered
     *
     * @return non-empty-string
     */
    private function print(array $data, \Closure $renderView, array $part): string
    {
        return $this->gotenbergClient->convertHtmlToPdf($renderView('internship/booklet.html.twig', $part + $data));
    }
}
