<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Progression;
use App\Entity\User;
use App\Enum\ProgressionExportMode;

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
        private readonly ProgressionTeacherRoster $teacherRoster,
    ) {
    }

    /**
     * There is ONE document and not two, whichever teacher of a co-animated matière presses the
     * button: what an auditor is handed must not depend on that. So the cover names every formateur
     * with the group they hold, and adds an « Établi par » row saying who produced this copy - which
     * is what lets a single document be exported by either of them without either appearing to be
     * the sole formateur.
     *
     * @param \Closure(string, array<string, mixed>): string $renderView  bound to the calling
     *                                                                   controller's renderView()
     * @param User|null                                     $generatedBy who pressed the button
     * @param ProgressionExportMode                         $mode        which of the two documents - see the enum
     *
     * @return non-empty-string raw PDF bytes
     */
    public function export(Progression $progression, \Closure $renderView, \DateTimeImmutable $generatedAt, ?User $generatedBy = null, ProgressionExportMode $mode = ProgressionExportMode::Dated): string
    {
        $data = $this->builder->build($progression, $mode);
        $progressionTitle = sprintf(
            'Progression pédagogique — %s × %s',
            $progression->getTopic()?->getName() ?? '—',
            $progression->getProgram()?->getDisplayShortName() ?? '—',
        );

        return $this->gotenbergClient->convertHtmlToPdf(
            $renderView('progression/qualiopi_export.html.twig', [
                'data' => $data,
                // Passed in rather than read here: the edition date is printed on every page and is
                // the one thing that would make two exports of an unchanged progression differ,
                // which is exactly what a test wants to hold still.
                'generatedAt' => $generatedAt,
                // One row per formateur, each with the group and the room their créneaux say they
                // hold - measured off the timetable, never stored.
                'roster' => $this->teacherRoster->forProgression($progression),
                'generatedBy' => $generatedBy,
                // Read by the template as one flag rather than by comparing the enum in Twig: the
                // undated document is a different reading of the same rows, and every place it
                // diverges asks the same yes/no question.
                'undated' => ProgressionExportMode::Undated === $mode,
            ]),
            // The running footer is Chromium's, not the document's - see GotenbergPageSetup. The
            // bottom margin is the taller one because it has to hold that band; the others are the
            // document's own breathing room, which used to be stated in an @page rule Chromium threw
            // away.
            new GotenbergPageSetup(
                footerHtml: $renderView('progression/_qualiopi_footer.html.twig', [
                    'docTitle' => $progressionTitle,
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
