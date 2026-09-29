<?php

declare(strict_types=1);

namespace App\Service\Portfolio;

use App\Entity\Portfolio;
use App\Entity\PortfolioEvidence;
use App\Entity\PortfolioShowcase;
use App\Entity\ReferentialBlock;
use App\Enum\PortfolioEvidenceKind;
use App\Service\GotenbergClient;
use App\Service\GotenbergPageSetup;
use App\Service\Rncp\ReferentialRncpImporter;

/**
 * The portfolio's PDFs, through Gotenberg like every other print of this application
 * (design/validated/portfolio.md §11):
 *
 * - the E5 synthesis table, A4 landscape, a replica of annexe VI-1 - validated work only;
 * - one E6 fiche, annexe VII-1-A (SISR) or VII-1-B (SLAM) by the letter of the option's block,
 *   « Contrôle en cours de formation » ticked, recto then verso « et éventuellement pages suivantes »;
 * - the E6 dossier: the page de présentation, fiche n° 1, fiche n° 2 - **each printed alone, then
 *   merged**, never cut from one print (Gotenberg 8.16's split damages about one PDF in two).
 *
 * Images among a fiche's éléments constitutifs are embedded as data URIs rather than fetched by
 * Gotenberg: the print must not depend on the renderer reaching the storage.
 */
class PortfolioPdfExporter
{
    private const int MAX_EMBEDDED_IMAGE = 5 * 1024 * 1024;

    public function __construct(
        private readonly GotenbergClient $gotenberg,
        private readonly PortfolioContext $context,
        private readonly PortfolioFileStore $files,
    ) {
    }

    /**
     * @param \Closure(string, array<string, mixed>): string $render the calling controller's renderView()
     *
     * @return non-empty-string
     */
    public function synthesis(SynthesisTable $table, \Closure $render): string
    {
        return $this->gotenberg->convertHtmlToPdf(
            $render('portfolio/pdf/synthesis.html.twig', ['table' => $table]),
            new GotenbergPageSetup(marginTop: '8mm', marginBottom: '8mm', marginLeft: '8mm', marginRight: '8mm'),
        );
    }

    /**
     * @param \Closure(string, array<string, mixed>): string $render
     *
     * @return non-empty-string
     */
    public function showcase(PortfolioShowcase $showcase, \Closure $render): string
    {
        $portfolio = $showcase->getPortfolio() ?? throw new \LogicException('A fiche belongs to a portfolio.');
        $block = $this->context->claimableBlocks($portfolio)['showcase'];
        $student = $portfolio->getStudent();
        $program = null === $student ? null : $this->context->currentProgram($student, $portfolio->getReferential());

        return $this->gotenberg->convertHtmlToPdf(
            $render('portfolio/pdf/showcase.html.twig', [
                'showcase' => $showcase,
                'block' => $block,
                'annex' => self::annexOf($block),
                'examTitle' => self::examTitleOf($block, $this->context->optionOfPortfolio($portfolio)?->getShortName()),
                'session' => $program?->getPortfolioExamSession(),
                'images' => $this->images($showcase),
            ]),
            new GotenbergPageSetup(marginTop: '12mm', marginBottom: '12mm', marginLeft: '14mm', marginRight: '14mm'),
        );
    }

    /**
     * @param list<PortfolioShowcase>                        $showcases the fiches to print, in order
     * @param \Closure(string, array<string, mixed>): string $render
     *
     * @return non-empty-string
     */
    public function e6Dossier(Portfolio $portfolio, array $showcases, \Closure $render): string
    {
        $student = $portfolio->getStudent();
        $program = null === $student ? null : $this->context->currentProgram($student, $portfolio->getReferential());
        $block = $this->context->claimableBlocks($portfolio)['showcase'];

        $pdfs = [$this->gotenberg->convertHtmlToPdf(
            $render('portfolio/pdf/e6_cover.html.twig', [
                'portfolio' => $portfolio,
                'session' => $program?->getPortfolioExamSession(),
                'examTitle' => self::examTitleOf($block, $this->context->optionOfPortfolio($portfolio)?->getShortName()),
            ]),
            new GotenbergPageSetup(marginTop: '15mm', marginBottom: '15mm', marginLeft: '15mm', marginRight: '15mm'),
        )];

        foreach ($showcases as $showcase) {
            $pdfs[] = $this->showcase($showcase, $render);
        }

        return $this->gotenberg->mergePdfs($pdfs);
    }

    /**
     * A page of plain HTML - the cover of a class's E6 export.
     *
     * @return non-empty-string
     */
    public function plainPage(string $html): string
    {
        return $this->gotenberg->convertHtmlToPdf($html, new GotenbergPageSetup(marginTop: '15mm', marginBottom: '15mm', marginLeft: '15mm', marginRight: '15mm'));
    }

    /**
     * @param non-empty-list<string> $pdfs
     *
     * @return non-empty-string
     */
    public function merge(array $pdfs): string
    {
        return $this->gotenberg->mergePdfs($pdfs);
    }

    /** « VII-1-A » for the block of option A (SISR), « VII-1-B » for option B (SLAM). */
    public static function annexOf(?ReferentialBlock $block): string
    {
        $letter = null === $block ? null : ReferentialRncpImporter::optionLetterOf($block->getLabel());

        return 'VII-1-'.($letter ?? 'A');
    }

    /** « Épreuve E6 - Administration des systèmes et des réseaux (option SISR) ». */
    public static function examTitleOf(?ReferentialBlock $block, ?string $option): string
    {
        $label = null === $block ? '' : trim((string) preg_replace('/\s*\(\s*Option\b.*$/u', '', $block->getLabel()));

        return 'Épreuve E6 - '.$label.(null === $option ? '' : ' (option '.$option.')');
    }

    /**
     * @return list<array{label: string, src: string}>
     */
    private function images(PortfolioShowcase $showcase): array
    {
        $images = [];
        foreach ($showcase->getEvidences() as $evidence) {
            if (!$this->embeddable($evidence)) {
                continue;
            }

            try {
                $bytes = $this->files->read((string) $evidence->getFileKey());
            } catch (\Throwable) {
                continue;
            }

            if (\strlen($bytes) <= self::MAX_EMBEDDED_IMAGE) {
                $images[] = ['label' => $evidence->getLabel(), 'src' => 'data:'.$evidence->getMimeType().';base64,'.base64_encode($bytes)];
            }
        }

        return $images;
    }

    private function embeddable(PortfolioEvidence $evidence): bool
    {
        return PortfolioEvidenceKind::File === $evidence->getKind()
            && null !== $evidence->getFileKey()
            && \in_array($evidence->getMimeType(), ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true);
    }
}
