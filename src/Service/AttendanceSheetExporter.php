<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Program;

/**
 * Renders templates/program/exports/attendance_sheet.html.twig and converts it to PDF via
 * Gotenberg - the same pipeline as the Livret Alternant, the TSF fiches and the Qualiopi export,
 * and for the same reason: the document is authored as HTML + print CSS, the only form somebody can
 * keep changing without touching PHP.
 *
 * The sheet carries no attendance of its own. It is a blank to sign on paper - a subject line, a
 * date line, one row per person - and the establishment's identity block is what makes the
 * signatures attestable. Nothing here reads or writes what anybody actually signed.
 */
class AttendanceSheetExporter
{
    public function __construct(
        private readonly GotenbergClient $gotenbergClient,
    ) {
    }

    /**
     * @param list<string>                                   $names      one line per person, already
     *                                                                    ordered and spelled by
     *                                                                    App\Service\ClassRoster
     * @param \Closure(string, array<string, mixed>): string $renderView bound to the calling
     *                                                                    controller's renderView()
     *
     * @return non-empty-string raw PDF bytes
     */
    public function export(Program $program, array $names, string $documentTitle, \Closure $renderView, \DateTimeImmutable $generatedAt): string
    {
        return $this->gotenbergClient->convertHtmlToPdf(
            $renderView('program/exports/attendance_sheet.html.twig', [
                'program' => $program,
                'names' => $names,
                'documentTitle' => $documentTitle,
                // Gotenberg has no browser origin of its own: every asset() is prefixed with this,
                // and `php` is the container serving them on the internal network.
                'assetBaseUrl' => 'http://php',
            ]),
            // The running footer is Chromium's, not the document's - see GotenbergPageSetup, which
            // also explains why these margins and the footer's own 186mm move together. The bottom
            // one is the taller because it has to hold the band.
            new GotenbergPageSetup(
                footerHtml: $renderView('program/exports/_attendance_footer.html.twig', [
                    'docTitle' => $documentTitle,
                    'generatedAt' => $generatedAt,
                ]),
                marginTop: '12mm',
                marginBottom: '16mm',
                marginLeft: '12mm',
                marginRight: '12mm',
            ),
        );
    }
}
