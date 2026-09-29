<?php

declare(strict_types=1);

namespace App\Controller\Portfolio;

use App\Attribute\RequiresFeature;
use App\Entity\Portfolio;
use App\Entity\PortfolioShowcase;
use App\Enum\Feature;
use App\Form\PortfolioEvidenceType;
use App\Service\Portfolio\PortfolioContext;
use App\Service\Portfolio\PortfolioEvidenceIntake;
use App\Service\Portfolio\PortfolioShowcaseChecker;
use App\Service\Portfolio\PortfolioShowcaseWriter;
use App\Service\Portfolio\PortfolioSnapshot;
use App\Service\Portfolio\PortfolioValidators;
use App\Service\PostValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Mon portfolio › Fiches E6 » (design/validated/portfolio.md, screens 7-8).
 *
 * Two slots, « Fiche n° 1 » and « Fiche n° 2 »; each opens from a validated réalisation that
 * retained at least one competency of the option's bloc 2, and the number is the one the fiche
 * prints. Under the slots, the coverage of bloc 2 says which fiche covers what. The tab exists only
 * in the second year of the cursus.
 */
#[RequiresFeature(Feature::Portfolio)]
class MyShowcaseController extends AbstractController
{
    use MyPortfolioTrait;

    public function __construct(
        private readonly PortfolioContext $context,
        private readonly PortfolioShowcaseWriter $writer,
        private readonly PortfolioValidators $validators,
        private readonly EntityManagerInterface $entityManager,
        #[Target('app.portfolio_body')]
        private readonly HtmlSanitizerInterface $sanitizer,
    ) {
    }

    #[Route(path: '/my/portfolio/showcases', name: 'app_my_portfolio_showcases', methods: ['GET'])]
    public function index(PortfolioShowcaseChecker $checker): Response
    {
        $portfolio = $this->secondYearPortfolio();
        $block = $this->context->claimableBlocks($portfolio)['showcase'];

        return $this->render('portfolio/my/showcases.html.twig', [
            'portfolio' => $portfolio,
            'block' => $block,
            'candidates' => $this->writer->candidates($portfolio, $block),
            'coverage' => $checker->check($block, $portfolio->getShowcases()),
            'option' => $this->context->optionOfPortfolio($portfolio),
            'cursusYear' => $this->context->cursusYear($portfolio),
            'activeTab' => 'showcases',
        ]);
    }

    #[Route(path: '/my/portfolio/showcases/{number}/open', name: 'app_my_portfolio_showcase_open', requirements: ['number' => '[12]'], methods: ['POST'])]
    public function open(int $number, Request $request): Response
    {
        $portfolio = $this->secondYearPortfolio();
        $this->assertToken($request);
        $achievement = $this->myAchievement($portfolio, PostValue::int($request, 'achievement'));

        try {
            $showcase = $this->writer->open($portfolio, $number, $achievement, $this->context->claimableBlocks($portfolio)['showcase']);
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('app_my_portfolio_showcases');
        }

        $this->entityManager->persist($showcase);
        $this->entityManager->flush();

        return $this->redirectToRoute('app_my_portfolio_showcase_edit', ['number' => $number]);
    }

    #[Route(path: '/my/portfolio/showcases/{number}', name: 'app_my_portfolio_showcase_edit', requirements: ['number' => '[12]'], methods: ['GET', 'POST'])]
    public function edit(int $number, Request $request): Response
    {
        $portfolio = $this->secondYearPortfolio();
        $showcase = $this->myShowcase($portfolio, $number);
        $student = $this->currentUser();
        $program = $this->context->currentProgram($student, $portfolio->getReferential());
        $reviewers = null === $program ? [] : $this->validators->validatorsFor($program, $this->context->optionOfPortfolio($portfolio));

        if ($request->isMethod('POST')) {
            $this->assertToken($request);
            $clean = fn (string $key): ?string => '' === trim(PostValue::string($request, $key)) ? null : $this->sanitizer->sanitize(PostValue::string($request, $key));
            $reopened = $this->writer->apply($showcase, $clean('conditions'), $clean('resources'), $clean('access'), $clean('description'), PostValue::nullableInt($request, 'requestedReviewer'), $reviewers);

            $errors = 'submit' === $request->request->get('action') ? $this->writer->submit($showcase) : [];
            $this->entityManager->flush();

            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }

            if ([] === $errors) {
                $this->addFlash('success', match (true) {
                    'submit' === $request->request->get('action') => 'portfolioShowcaseSubmittedFlashMessage',
                    $reopened => 'portfolioShowcaseReopenedFlashMessage',
                    default => 'portfolioShowcaseSavedFlashMessage',
                });
            }

            return $this->redirectToRoute('app_my_portfolio_showcase_edit', ['number' => $number]);
        }

        return $this->render('portfolio/my/showcase_edit.html.twig', [
            'portfolio' => $portfolio,
            'showcase' => $showcase,
            'option' => $this->context->optionOfPortfolio($portfolio),
            'block' => $this->context->claimableBlocks($portfolio)['showcase'],
            'reviewers' => $reviewers,
            'submissionErrors' => $this->writer->submissionErrors($showcase),
            'evidenceForm' => $this->createForm(PortfolioEvidenceType::class, null, [
                'action' => $this->generateUrl('app_my_portfolio_showcase_evidence', ['number' => $number]),
            ]),
        ]);
    }

    /** A fiche never submitted can be closed, freeing its slot and its réalisation. */
    #[Route(path: '/my/portfolio/showcases/{number}/delete', name: 'app_my_portfolio_showcase_delete', requirements: ['number' => '[12]'], methods: ['POST'])]
    public function delete(int $number, Request $request): Response
    {
        $portfolio = $this->secondYearPortfolio();
        $showcase = $this->myShowcase($portfolio, $number);
        $this->assertToken($request);

        if (0 !== $showcase->getRevision()) {
            throw $this->createNotFoundException();
        }

        $portfolio->getShowcases()->removeElement($showcase);
        $this->entityManager->remove($showcase);
        $this->entityManager->flush();
        $this->addFlash('success', 'portfolioShowcaseDeletedFlashMessage');

        return $this->redirectToRoute('app_my_portfolio_showcases');
    }

    #[Route(path: '/my/portfolio/showcases/{number}/evidence', name: 'app_my_portfolio_showcase_evidence', requirements: ['number' => '[12]'], methods: ['POST'])]
    public function addEvidence(int $number, Request $request, PortfolioEvidenceIntake $intake): Response
    {
        $portfolio = $this->secondYearPortfolio();
        $showcase = $this->myShowcase($portfolio, $number);
        $form = $this->createForm(PortfolioEvidenceType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $before = PortfolioSnapshot::ofShowcase($showcase);
            $evidence = $intake->fromForm($form, $showcase);

            if (\is_string($evidence)) {
                $this->addFlash('error', $evidence);
            } else {
                $this->entityManager->persist($evidence);
                $reopened = $this->writer->afterChange($showcase, $before);
                $this->entityManager->flush();
                $this->addFlash('success', $reopened ? 'portfolioShowcaseReopenedFlashMessage' : 'portfolioEvidenceAddedFlashMessage');
            }
        } else {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('error', $error->getMessage());
            }
        }

        return $this->redirectToRoute('app_my_portfolio_showcase_edit', ['number' => $number]);
    }

    #[Route(path: '/my/portfolio/showcases/{number}/evidence/{evidenceId}/remove', name: 'app_my_portfolio_showcase_evidence_remove', requirements: ['number' => '[12]', 'evidenceId' => '\d+'], methods: ['POST'])]
    public function removeEvidence(int $number, int $evidenceId, Request $request): Response
    {
        $portfolio = $this->secondYearPortfolio();
        $showcase = $this->myShowcase($portfolio, $number);
        $this->assertToken($request);

        foreach ($showcase->getEvidences() as $evidence) {
            if ($evidence->getId() === $evidenceId) {
                $before = PortfolioSnapshot::ofShowcase($showcase);
                $showcase->removeEvidence($evidence);
                $reopened = $this->writer->afterChange($showcase, $before);
                $this->entityManager->flush();
                $this->addFlash('success', $reopened ? 'portfolioShowcaseReopenedFlashMessage' : 'portfolioEvidenceRemovedFlashMessage');

                return $this->redirectToRoute('app_my_portfolio_showcase_edit', ['number' => $number]);
            }
        }

        throw $this->createNotFoundException();
    }

    /** The E6 is prepared in the second year of the cursus; before it, the tab does not exist. */
    private function secondYearPortfolio(): Portfolio
    {
        $portfolio = $this->myPortfolio($this->context);

        if ($this->context->cursusYear($portfolio) < 2) {
            throw $this->createNotFoundException();
        }

        return $portfolio;
    }

    private function myShowcase(Portfolio $portfolio, int $number): PortfolioShowcase
    {
        return $portfolio->getShowcase($number) ?? throw $this->createNotFoundException();
    }
}
