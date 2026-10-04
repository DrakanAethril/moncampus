<?php

declare(strict_types=1);

namespace App\Service\Ecf;

use App\Service\GotenbergClient;
use App\Service\GotenbergPageSetup;
use Twig\Environment;

/**
 * The ECF booklet as a PDF, page for page like the ministry's template (R10): one HTML document,
 * one Gotenberg conversion, the template's footer table in the bottom margin.
 *
 * The margins are the template's second section's - right 19 mm, left 15 mm - with the top one
 * 7.8 pt lower than Word's 15 mm, where Word starts a table row continued on a new page (the
 * template pulls its absolutely placed pages back up by as much), and a bottom margin of 27 mm that
 * holds the footer table where Word draws it. They must move together
 * with templates/ufa/ecf/_print_footer.html.twig, which places its table from the paper's edge.
 */
class EcfBookletPdfExporter
{
    public function __construct(
        private readonly Environment $twig,
        private readonly GotenbergClient $gotenbergClient,
        private readonly EcfPrintBuilder $printBuilder,
    ) {
    }

    /** @return non-empty-string */
    public function export(EcfOverview $overview): string
    {
        $data = $this->printBuilder->build($overview);
        $html = $this->twig->render('ufa/ecf/print.html.twig', $this->context($overview, $data, false));
        $footer = $this->twig->render('ufa/ecf/_print_footer.html.twig', ['footer' => $data['footer']]);

        return $this->gotenbergClient->convertHtmlToPdf($html, new GotenbergPageSetup(
            footerHtml: $footer,
            marginTop: '17.75mm',
            marginBottom: '27mm',
            marginLeft: '15mm',
            marginRight: '19mm',
        ));
    }

    /** The same document for the online reader's frame. */
    public function screen(EcfOverview $overview): string
    {
        return $this->twig->render('ufa/ecf/print.html.twig', $this->context($overview, $this->printBuilder->build($overview), true));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function context(EcfOverview $overview, array $data, bool $screen): array
    {
        return [...$data, 'settings' => $overview->settings, 'booklet' => $overview->booklet, 'student' => $overview->booklet->getStudent(), 'screen' => $screen];
    }
}
