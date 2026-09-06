<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Program;

/**
 * Renders templates/program/exports/checklist_sheet.html.twig and converts it to PDF via Gotenberg -
 * the « Liste pour pointage » of the student list.
 *
 * The sibling of App\Service\AttendanceSheetExporter, and separate from it on purpose: the émargement
 * sheet is a fixed document that always prints the same three columns, this one is a blank whose
 * columns the person printing it names (see App\Service\ChecklistSheetOptions). One is signed, the
 * other is ticked; merging them would mean a document that means two things.
 *
 * Orientation travels in the document's own `@page { size: … }` rather than as a Gotenberg field,
 * the client sending `preferCssPageSize` - but the running footer is Chromium's, so its band has to
 * be told the width the page ended up with.
 */
class ChecklistSheetExporter
{
    /** A4 less the exporter's own two side margins, portrait then landscape. */
    private const string BAND_WIDTH_PORTRAIT = '186mm';
    private const string BAND_WIDTH_LANDSCAPE = '273mm';

    public function __construct(
        private readonly GotenbergClient $gotenbergClient,
    ) {
    }

    /**
     * @param list<string>                                   $names      one line per student, already
     *                                                                    ordered and spelled by
     *                                                                    App\Service\ClassRoster
     * @param \Closure(string, array<string, mixed>): string $renderView bound to the calling
     *                                                                    controller's renderView()
     *
     * @return non-empty-string raw PDF bytes
     */
    public function export(Program $program, array $names, ChecklistSheetOptions $options, string $documentTitle, \Closure $renderView, \DateTimeImmutable $generatedAt): string
    {
        return $this->gotenbergClient->convertHtmlToPdf(
            $renderView('program/exports/checklist_sheet.html.twig', [
                'program' => $program,
                'names' => $names,
                'columns' => $options->columns,
                'landscape' => $options->landscape,
                'documentTitle' => $documentTitle,
                // Gotenberg has no browser origin of its own: every asset() is prefixed with this,
                // and `php` is the container serving them on the internal network.
                'assetBaseUrl' => 'http://php',
            ]),
            new GotenbergPageSetup(
                footerHtml: $renderView('program/exports/_attendance_footer.html.twig', [
                    'docTitle' => $documentTitle,
                    'generatedAt' => $generatedAt,
                    'bandWidth' => $options->landscape ? self::BAND_WIDTH_LANDSCAPE : self::BAND_WIDTH_PORTRAIT,
                ]),
                marginTop: '12mm',
                marginBottom: '16mm',
                marginLeft: '12mm',
                marginRight: '12mm',
            ),
        );
    }
}
