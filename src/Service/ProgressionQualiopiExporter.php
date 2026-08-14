<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Progression;

/**
 * Renders templates/progression/qualiopi_export.html.twig and converts it to PDF via Gotenberg -
 * the same pipeline as the Livret Alternant (App\Service\InternshipBookletPdfExporter), and for the
 * same reason: the document is authored as HTML + print CSS, which is the only form a teacher or a
 * designer can keep changing without touching PHP.
 *
 * No merge step here, unlike the Livret: this document is generated end to end and never has to
 * swallow an uploaded PDF, so one conversion is the whole job.
 */
class ProgressionQualiopiExporter
{
    public function __construct(
        private readonly ProgressionQualiopiBuilder $builder,
        private readonly GotenbergClient $gotenbergClient,
    ) {
    }

    /**
     * @param \Closure(string, array<string, mixed>): string $renderView bound to the calling
     *                                                                  controller's renderView()
     *
     * @return non-empty-string raw PDF bytes
     */
    public function export(Progression $progression, \Closure $renderView, \DateTimeImmutable $generatedAt): string
    {
        return $this->gotenbergClient->convertHtmlToPdf($renderView('progression/qualiopi_export.html.twig', [
            'data' => $this->builder->build($progression),
            // Passed in rather than read here: the edition date is printed on every page and is the
            // one thing that would make two exports of an unchanged progression differ, which is
            // exactly what a test wants to hold still.
            'generatedAt' => $generatedAt,
        ]));
    }
}
