<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Program;

/**
 * Renders templates/program/exports/signature_sheets.html.twig and converts it to PDF via Gotenberg
 * - the same pipeline as the émargement sheet of the class lists, the TSF fiches and the Livret
 * Alternant, and for the same reason: the document is authored as HTML + print CSS, the only form
 * somebody can keep changing without touching PHP.
 *
 * It prints exactly what the « Feuilles d'émargement » tab has just previewed on screen: one sheet
 * per day - per option, when the formation has any - the day's créneaux as columns, one row per
 * student to sign in. The sheets are built by App\Controller\ProgramExportsController and handed
 * over untouched, so the preview and the file can never disagree about who is on them: the exporter
 * decides nothing but how they land on a page - not even how a student is named, App\Service\
 * ClassRoster having spelled the rows before they got here.
 *
 * Landscape, unlike its two portrait neighbours: this sheet is a grid whose width grows with the
 * day, and a name plus half a dozen créneaux does not fit across an A4 standing up.
 *
 * @phpstan-type SignatureSheetSession array{startHour: string, endHour: string, title: string, teacherName: string}
 * @phpstan-type SignatureSheet array{optionLabel: string|null, day: string, sessions: list<SignatureSheetSession>, studentNames: list<string>}
 */
class SignatureSheetExporter
{
    public function __construct(
        private readonly GotenbergClient $gotenbergClient,
    ) {
    }

    /**
     * @param list<SignatureSheet>                           $sheets     one entry per printed page
     * @param \Closure(string, array<string, mixed>): string $renderView bound to the calling controller's renderView()
     *
     * @return non-empty-string raw PDF bytes
     */
    public function export(Program $program, array $sheets, string $documentTitle, \Closure $renderView, \DateTimeImmutable $generatedAt): string
    {
        return $this->gotenbergClient->convertHtmlToPdf(
            $renderView('program/exports/signature_sheets.html.twig', [
                'program' => $program,
                'sheets' => $sheets,
                'documentTitle' => $documentTitle,
                // Gotenberg has no browser origin of its own: every asset() is prefixed with this,
                // and `php` is the container serving them on the internal network.
                'assetBaseUrl' => 'http://php',
            ]),
            // The running footer is Chromium's, not the document's - see GotenbergPageSetup, which
            // also explains why these margins and the footer's own band width move together. The
            // band is the printable width of an A4 turned on its side: 297mm less the two 10mm
            // side margins.
            new GotenbergPageSetup(
                footerHtml: $renderView('program/exports/_attendance_footer.html.twig', [
                    'docTitle' => $documentTitle,
                    'generatedAt' => $generatedAt,
                    'bandWidth' => '277mm',
                ]),
                marginTop: '10mm',
                marginBottom: '14mm',
                marginLeft: '10mm',
                marginRight: '10mm',
            ),
        );
    }
}
