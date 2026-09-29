<?php

declare(strict_types=1);

namespace App\Controller\Portfolio;

use App\Attribute\RequiresFeature;
use App\Enum\Feature;
use App\Repository\ReferentialTemplateRepository;
use App\Service\Portfolio\PortfolioContext;
use App\Service\Portfolio\PortfolioPrefill;
use App\Service\Portfolio\PortfolioSectionResolver;
use App\Service\Portfolio\PortfolioSynthesis;
use App\Service\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Mon portfolio » - the réalisations and the synthesis table (design/validated/portfolio.md, screens 1 and 3).
 *
 * The réalisations are listed in the three parts of the official table, each with its state and its
 * competencies - full when retained, dashed while waiting. The table tab is the annexe VI-1 itself,
 * computed (App\Service\Portfolio\PortfolioSynthesis): nobody ticks it.
 */
#[RequiresFeature(Feature::Portfolio)]
class MyPortfolioController extends AbstractController
{
    use MyPortfolioTrait;

    public function __construct(
        private readonly PortfolioContext $context,
        private readonly PortfolioSynthesis $synthesis,
    ) {
    }

    #[Route(path: '/my/portfolio', name: 'app_my_portfolio', methods: ['GET'])]
    public function index(PortfolioSectionResolver $sections, PortfolioPrefill $prefill): Response
    {
        $portfolio = $this->myPortfolio($this->context);
        $years = $this->context->yearSpans($portfolio);

        // Drafts included - this is the student's own list - filed where the table will put them.
        $parts = [1 => [], 2 => [], 3 => []];
        foreach ($portfolio->getAchievements() as $achievement) {
            foreach ($sections->sections($achievement->getSetting(), $achievement->getStartsOn(), $achievement->getEndsOn(), $years) as $part) {
                $parts[$part][] = $achievement;
            }
        }

        return $this->render('portfolio/my/index.html.twig', [
            'portfolio' => $portfolio,
            'parts' => $parts,
            'table' => $this->synthesis->build($portfolio, true),
            'option' => $this->context->optionOfPortfolio($portfolio),
            'cursusYear' => $this->context->cursusYear($portfolio),
            'activeTab' => 'achievements',
            'engagementCandidates' => $prefill->engagementCandidates($portfolio),
        ]);
    }

    #[Route(path: '/my/portfolio/synthesis', name: 'app_my_portfolio_synthesis', methods: ['GET'])]
    public function synthesis(Request $request, ReferentialTemplateRepository $templates): Response
    {
        $portfolio = $this->myPortfolio($this->context);
        $hidePending = QueryValue::bool($request, 'hidePending');
        $program = $this->context->currentProgram($this->currentUser(), $portfolio->getReferential());
        $referential = $portfolio->getReferential();

        return $this->render('portfolio/my/synthesis.html.twig', [
            'portfolio' => $portfolio,
            'table' => $this->synthesis->build($portfolio, !$hidePending),
            'hidePending' => $hidePending,
            'template_in_service' => null !== $referential && null !== $templates->findInService($referential, $program?->getPortfolioExamSession()),
            'option' => $this->context->optionOfPortfolio($portfolio),
            'cursusYear' => $this->context->cursusYear($portfolio),
            'activeTab' => 'synthesis',
        ]);
    }
}
