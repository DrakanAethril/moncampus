<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Option;
use App\Entity\Program;

/**
 * Renders templates/program/referential/tsf_export.html.twig and converts it to PDF via Gotenberg -
 * the same pipeline as the Livret Alternant and the Qualiopi progression export, and for the same
 * reason: the document is authored as HTML + print CSS, the only form somebody can keep changing
 * without touching PHP.
 */
class TsfFicheExporter
{
    public function __construct(
        private readonly TsfFicheBuilder $builder,
        private readonly GotenbergClient $gotenbergClient,
    ) {
    }

    /**
     * @param \Closure(string, array<string, mixed>): string $renderView bound to the calling
     *                                                                  controller's renderView()
     * @param Option|null                                   $only       restricts the export to one
     *                                                                  option, see TsfFicheBuilder
     *
     * @return non-empty-string raw PDF bytes
     */
    public function export(Program $program, \Closure $renderView, \DateTimeImmutable $generatedAt, ?Option $only = null): string
    {
        $title = sprintf('Référentiel de formation — %s', $program->getName());
        if (null !== $only) {
            $title .= ' — '.$only->getShortName();
        }

        return $this->gotenbergClient->convertHtmlToPdf(
            $renderView('program/referential/tsf_export.html.twig', [
                'program' => $program,
                'center' => $this->builder->formationCenter(),
                'fiches' => $this->builder->build($program, $only),
                // Passed in rather than read here: the edition date is printed on every page and is
                // the one thing that would make two exports of an unchanged referential differ.
                'generatedAt' => $generatedAt,
            ]),
            // The running footer is Chromium's, not the document's - see GotenbergPageSetup. The
            // bottom margin is the taller one because it has to hold that band.
            new GotenbergPageSetup(
                footerHtml: $renderView('program/referential/_tsf_footer.html.twig', [
                    'docTitle' => $title,
                    'generatedAt' => $generatedAt,
                ]),
                marginTop: '10mm',
                marginBottom: '16mm',
                marginLeft: '12mm',
                marginRight: '12mm',
            ),
        );
    }
}
