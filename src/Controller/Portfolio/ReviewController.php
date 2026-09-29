<?php

declare(strict_types=1);

namespace App\Controller\Portfolio;

use App\Attribute\RequiresFeature;
use App\Enum\Feature;
use App\Enum\PortfolioReviewDecision;
use App\Repository\PortfolioAchievementRepository;
use App\Repository\PortfolioShowcaseRepository;
use App\Security\StructureAccessChecker;
use App\Security\Voter\PortfolioVoter;
use App\Service\Portfolio\PortfolioContext;
use App\Service\Portfolio\PortfolioQueue;
use App\Service\Portfolio\PortfolioReviewer;
use App\Service\Portfolio\PortfolioShowcaseChecker;
use App\Service\Portfolio\PortfolioSnapshot;
use App\Service\Portfolio\PortfolioValidators;
use App\Service\PostValue;
use App\Service\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Outils › Portfolios › À valider » and the review screen (design/validated/portfolio.md, screens 4-5).
 *
 * The queue is the validateur's own (App\Service\Portfolio\PortfolioQueue). The review screen puts
 * the piece as the student wrote it on the left - with, for a réalisation validated before and
 * changed since, what changed - and the decision on the right: retained or not per competency, a
 * comment, then « Valider » or « Renvoyer à l'étudiant ». The rules are App\Service\Portfolio\
 * PortfolioReviewer's; the right to decide is the Voter's REVIEW, which no rank grants (§1).
 */
#[RequiresFeature(Feature::Portfolio)]
class ReviewController extends AbstractController
{
    use PortfolioReviewTrait;

    public function __construct(
        private readonly PortfolioValidators $validators,
        private readonly StructureAccessChecker $access,
        private readonly PortfolioReviewer $reviewer,
    ) {
    }

    #[Route(path: '/portfolios', name: 'app_portfolios', methods: ['GET'])]
    public function queue(Request $request, PortfolioQueue $queue): Response
    {
        $this->assertReviewArea($this->validators, $this->access);
        $teacher = $this->currentUser();

        if (!$this->validators->isValidator($teacher)) {
            return $this->redirectToRoute('app_portfolios_classes');
        }

        $pair = QueryValue::trimmed($request, 'class');

        return $this->render('portfolio/review/queue.html.twig', [
            'items' => $queue->pendingFor($teacher, $pair),
            'total' => $queue->countFor($teacher),
            'pairs' => $queue->pairsOf($teacher),
            'currentPair' => $pair,
            'activeTab' => 'queue',
        ]);
    }

    #[Route(path: '/portfolios/achievements/{id}', name: 'app_portfolios_achievement', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function achievement(int $id, PortfolioAchievementRepository $achievements, PortfolioContext $context): Response
    {
        $achievement = $achievements->find($id) ?? throw $this->createNotFoundException();

        if (!$this->isGranted(PortfolioVoter::VIEW, $achievement)) {
            throw $this->createNotFoundException();
        }

        $portfolio = $achievement->getPortfolio() ?? throw $this->createNotFoundException();
        $student = $portfolio->getStudent();
        $lastValidation = $achievement->getLastValidation();
        $changed = null !== $lastValidation && !$achievement->isValidated()
            ? PortfolioSnapshot::changedKeys($lastValidation->getSnapshot(), PortfolioSnapshot::ofAchievement($achievement))
            : [];

        return $this->render('portfolio/review/achievement.html.twig', [
            'achievement' => $achievement,
            'portfolio' => $portfolio,
            'program' => null === $student ? null : $context->currentProgram($student, $portfolio->getReferential()),
            'canDecide' => $this->isGranted(PortfolioVoter::REVIEW, $achievement) && $achievement->getState()->isAwaitingDecision(),
            'changed' => $changed,
            'lastValidation' => $lastValidation,
        ]);
    }

    #[Route(path: '/portfolios/achievements/{id}/decide', name: 'app_portfolios_achievement_decide', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function decideAchievement(int $id, Request $request, PortfolioAchievementRepository $achievements): Response
    {
        $achievement = $achievements->find($id) ?? throw $this->createNotFoundException();

        if (!$this->isGranted(PortfolioVoter::REVIEW, $achievement)) {
            throw $this->createNotFoundException();
        }

        $this->assertToken($request);
        $decision = PortfolioReviewDecision::tryFrom(PostValue::string($request, 'decision')) ?? throw $this->createNotFoundException();

        $retained = [];
        $comments = [];
        $posted = PostValue::all($request, 'retained');
        $postedComments = PostValue::all($request, 'claimComment');
        foreach ($achievement->getClaims() as $claim) {
            $competencyId = (int) $claim->getCompetency()?->getId();
            $value = $posted[(string) $competencyId] ?? null;
            if ('1' === $value || '0' === $value) {
                $retained[$competencyId] = '1' === $value;
            }
            $comment = $postedComments[(string) $competencyId] ?? null;
            $comments[$competencyId] = \is_string($comment) && '' !== trim($comment) ? trim($comment) : null;
        }

        $errors = $this->reviewer->decideAchievement($achievement, $this->currentUser(), $decision, PostValue::trimmed($request, 'comment'), $retained, $comments);

        if ([] !== $errors) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }

            return $this->redirectToRoute('app_portfolios_achievement', ['id' => $achievement->getId()]);
        }

        $this->addFlash('success', PortfolioReviewDecision::Validated === $decision ? 'portfolioReviewValidatedFlashMessage' : 'portfolioReviewSentBackFlashMessage');

        return $this->redirectToRoute('app_portfolios');
    }

    #[Route(path: '/portfolios/showcases/{id}', name: 'app_portfolios_showcase', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function showcase(int $id, PortfolioShowcaseRepository $showcases, PortfolioContext $context, PortfolioShowcaseChecker $checker): Response
    {
        $showcase = $showcases->find($id) ?? throw $this->createNotFoundException();

        if (!$this->isGranted(PortfolioVoter::VIEW, $showcase)) {
            throw $this->createNotFoundException();
        }

        $portfolio = $showcase->getPortfolio() ?? throw $this->createNotFoundException();
        $student = $portfolio->getStudent();

        return $this->render('portfolio/review/showcase.html.twig', [
            'showcase' => $showcase,
            'portfolio' => $portfolio,
            'program' => null === $student ? null : $context->currentProgram($student, $portfolio->getReferential()),
            'option' => $context->optionOfPortfolio($portfolio),
            'block' => $context->claimableBlocks($portfolio)['showcase'],
            'canDecide' => $this->isGranted(PortfolioVoter::REVIEW, $showcase) && $showcase->getState()->isAwaitingDecision(),
            'coverage' => $checker->check($context->claimableBlocks($portfolio)['showcase'], $portfolio->getShowcases()),
        ]);
    }

    #[Route(path: '/portfolios/showcases/{id}/decide', name: 'app_portfolios_showcase_decide', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function decideShowcase(int $id, Request $request, PortfolioShowcaseRepository $showcases): Response
    {
        $showcase = $showcases->find($id) ?? throw $this->createNotFoundException();

        if (!$this->isGranted(PortfolioVoter::REVIEW, $showcase)) {
            throw $this->createNotFoundException();
        }

        $this->assertToken($request);
        $decision = PortfolioReviewDecision::tryFrom(PostValue::string($request, 'decision')) ?? throw $this->createNotFoundException();
        $errors = $this->reviewer->decideShowcase($showcase, $this->currentUser(), $decision, PostValue::trimmed($request, 'comment'), PostValue::bool($request, 'environmentCompliant'));

        if ([] !== $errors) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }

            return $this->redirectToRoute('app_portfolios_showcase', ['id' => $showcase->getId()]);
        }

        $this->addFlash('success', PortfolioReviewDecision::Validated === $decision ? 'portfolioReviewValidatedFlashMessage' : 'portfolioReviewSentBackFlashMessage');

        return $this->redirectToRoute('app_portfolios');
    }
}
