<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Wiki;
use App\Entity\WikiNode;

/**
 * Renders a wiki - or one branch of it - to PDF through Gotenberg, the same pipeline as the Livret
 * Alternant and the Qualiopi export, and for the same reason: the document is authored as HTML plus
 * print CSS, which is the only form somebody can keep changing without touching PHP.
 *
 * Four traps this repository has already paid for are avoided by construction, and each is
 * commented where it applies:
 *
 *  - the HTML declares `<meta charset>`, or Chromium guesses GBK and every accent prints as an
 *    ideogram;
 *  - the running footer is Gotenberg's, never a `position: fixed` band in the document, which
 *    Chromium repeats across the top of every page;
 *  - that footer is laid out with a table at an explicit width, flex being ignored there and `100%`
 *    resolving against something wider than the printable area;
 *  - the body reserves two pixels on the right, because Chromium rounds the content box up to a
 *    whole CSS pixel and then clips to the real millimetre - which cuts the right border off every
 *    full-width block.
 */
class WikiPdfExporter
{
    public function __construct(
        private readonly WikiExportBuilder $builder,
        private readonly WikiPdfStylesheet $stylesheet,
        private readonly GotenbergClient $gotenbergClient,
    ) {
    }

    /**
     * @param \Closure(string, array<string, mixed>): string $renderView bound to the calling
     *                                                                  controller's renderView()
     *
     * @return non-empty-string raw PDF bytes
     */
    public function export(
        Wiki $wiki,
        ?WikiNode $root,
        bool $singlePage,
        \Closure $renderView,
        \DateTimeImmutable $generatedAt,
    ): string {
        $pages = $this->builder->pages($wiki, $root, $singlePage);
        $title = null === $root ? $wiki->getTitle() : $root->getTitle();

        return $this->gotenbergClient->convertHtmlToPdf(
            $renderView('wiki/pdf/export.html.twig', [
                'wiki' => $wiki,
                'pages' => $pages,
                'documentTitle' => $title,
                'audience' => $this->audienceOf($wiki),
                // Passed in rather than read here: the edition date is printed on every page and is
                // the one thing that would make two exports of an unchanged wiki differ.
                'generatedAt' => $generatedAt,
                'katexCss' => $this->stylesheet->katex(),
            ]),
            new GotenbergPageSetup(
                footerHtml: $renderView('wiki/pdf/_footer.html.twig', [
                    'documentTitle' => $title,
                    'generatedAt' => $generatedAt,
                ]),
                marginTop: '12mm',
                marginBottom: '16mm',
                marginLeft: '12mm',
                marginRight: '12mm',
            ),
        );
    }

    /**
     * Who the wiki belongs to, for the cover: its owner for a personal wiki, its classes for one
     * assigned to them, otherwise its members.
     */
    private function audienceOf(Wiki $wiki): string
    {
        $owner = $wiki->getOwner();

        if (null !== $owner) {
            return $owner->getDisplayName() ?? $owner->getUsername();
        }

        $programs = [];

        foreach ($wiki->getPrograms() as $program) {
            $programs[] = $program->getDisplayShortName();
        }

        if ([] !== $programs) {
            return implode(', ', $programs);
        }

        $members = [];

        foreach ($wiki->getMembers() as $member) {
            $members[] = $member->getDisplayName() ?? $member->getUsername();
        }

        return implode(', ', $members);
    }
}
