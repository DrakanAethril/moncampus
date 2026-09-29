<?php

declare(strict_types=1);

namespace App\Controller\Portfolio;

use App\Attribute\RequiresFeature;
use App\Enum\Feature;
use App\Repository\ReferentialTemplateRepository;
use App\Service\Portfolio\E5SynthesisXlsxWriter;
use App\Service\Portfolio\PortfolioContext;
use App\Service\Portfolio\PortfolioFileStore;
use App\Service\Portfolio\PortfolioPdfExporter;
use App\Service\Portfolio\PortfolioSynthesis;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The student's own downloads: the synthesis table in the session's official .xlsx and in PDF, one
 * E6 fiche, the E6 dossier as it stands. Validated work only in the table (R5); the fiches print as
 * they are, since the student reads them before submitting.
 */
#[RequiresFeature(Feature::Portfolio)]
class MyExportController extends AbstractController
{
    use MyPortfolioTrait;
    use PortfolioDownloadTrait;

    public function __construct(
        private readonly PortfolioContext $context,
        private readonly PortfolioSynthesis $synthesis,
        private readonly PortfolioPdfExporter $pdf,
    ) {
    }

    #[Route(path: '/my/portfolio/synthesis.xlsx', name: 'app_my_portfolio_synthesis_xlsx', methods: ['GET'])]
    public function synthesisXlsx(ReferentialTemplateRepository $templates, E5SynthesisXlsxWriter $writer, PortfolioFileStore $files): Response
    {
        $portfolio = $this->myPortfolio($this->context);
        $program = $this->context->currentProgram($this->currentUser(), $portfolio->getReferential());
        $referential = $portfolio->getReferential() ?? throw $this->createNotFoundException();
        $template = $templates->findInService($referential, $program?->getPortfolioExamSession()) ?? throw $this->createNotFoundException();

        $local = $files->localCopy($template->getFileKey());
        try {
            $bytes = $writer->write($local, $template->getAnchors(), $this->synthesis->build($portfolio));
        } finally {
            @unlink($local);
        }

        return $this->download($bytes, 'tableau-synthese-e5-'.self::slug($this->currentUser()->getDisplayName() ?? 'portfolio').'.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    #[Route(path: '/my/portfolio/synthesis.pdf', name: 'app_my_portfolio_synthesis_pdf', methods: ['GET'])]
    public function synthesisPdf(): Response
    {
        $portfolio = $this->myPortfolio($this->context);
        $bytes = $this->pdf->synthesis($this->synthesis->build($portfolio), $this->renderView(...));

        return $this->download($bytes, 'tableau-synthese-e5-'.self::slug($this->currentUser()->getDisplayName() ?? 'portfolio').'.pdf', 'application/pdf');
    }

    #[Route(path: '/my/portfolio/showcases/{number}/pdf', name: 'app_my_portfolio_showcase_pdf', requirements: ['number' => '[12]'], methods: ['GET'])]
    public function showcasePdf(int $number): Response
    {
        $portfolio = $this->myPortfolio($this->context);
        $showcase = $portfolio->getShowcase($number) ?? throw $this->createNotFoundException();

        return $this->download($this->pdf->showcase($showcase, $this->renderView(...)), 'fiche-e6-'.$number.'.pdf', 'application/pdf');
    }

    #[Route(path: '/my/portfolio/e6.pdf', name: 'app_my_portfolio_e6_pdf', methods: ['GET'])]
    public function e6Pdf(): Response
    {
        $portfolio = $this->myPortfolio($this->context);
        $showcases = array_values($portfolio->getShowcases()->toArray());

        if ([] === $showcases) {
            throw $this->createNotFoundException();
        }

        return $this->download($this->pdf->e6Dossier($portfolio, $showcases, $this->renderView(...)), 'dossier-e6-'.self::slug($this->currentUser()->getDisplayName() ?? 'portfolio').'.pdf', 'application/pdf');
    }
}
