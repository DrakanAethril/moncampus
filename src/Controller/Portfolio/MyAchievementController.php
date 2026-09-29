<?php

declare(strict_types=1);

namespace App\Controller\Portfolio;

use App\Attribute\RequiresFeature;
use App\Entity\PortfolioAchievement;
use App\Enum\Feature;
use App\Enum\PortfolioSetting;
use App\Form\PortfolioEvidenceType;
use App\Service\Portfolio\AchievementInput;
use App\Service\Portfolio\PortfolioAchievementWriter;
use App\Service\Portfolio\PortfolioContext;
use App\Service\Portfolio\PortfolioEvidenceIntake;
use App\Service\Portfolio\PortfolioSnapshot;
use App\Service\Portfolio\PortfolioValidators;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Nouvelle réalisation » and the student's own réalisation (design/validated/portfolio.md, screen 2).
 *
 * The form carries the line of the E5 table and what an E6 fiche will read from it. Every ticked
 * competency asks for one sentence saying what, in the réalisation, mobilises it - that is what the
 * validateur reads to decide. The rules live in App\Service\Portfolio\PortfolioAchievementWriter:
 * a validated réalisation that changes goes back to « À valider » (R3), its E6 fiche with it (R11).
 */
#[RequiresFeature(Feature::Portfolio)]
class MyAchievementController extends AbstractController
{
    use MyPortfolioTrait;

    public function __construct(
        private readonly PortfolioContext $context,
        private readonly PortfolioAchievementWriter $writer,
        private readonly PortfolioValidators $validators,
        private readonly EntityManagerInterface $entityManager,
        #[Target('app.portfolio_body')]
        private readonly HtmlSanitizerInterface $sanitizer,
    ) {
    }

    #[Route(path: '/my/portfolio/achievements/new', name: 'app_my_portfolio_achievement_new', methods: ['GET', 'POST'])]
    #[Route(path: '/my/portfolio/achievements/{id}/edit', name: 'app_my_portfolio_achievement_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function form(Request $request, ?int $id = null): Response
    {
        $portfolio = $this->myPortfolio($this->context);
        $achievement = null === $id ? null : $this->myAchievement($portfolio, $id);
        $student = $this->currentUser();
        $program = $this->context->currentProgram($student, $portfolio->getReferential());
        $reviewers = null === $program ? [] : $this->validators->validatorsFor($program, $this->context->optionOfPortfolio($portfolio));
        $errors = [];

        if ($request->isMethod('POST')) {
            $this->assertToken($request);
            $input = AchievementInput::fromRequest($request, fn (string $html): string => $this->sanitizer->sanitize($html));
            $isNew = null === $achievement;
            $achievement ??= new PortfolioAchievement($portfolio);

            if ('' === $input->title) {
                $errors[] = 'portfolioAchievementTitleRequiredError';
                if ($isNew) {
                    $portfolio->getAchievements()->removeElement($achievement);
                    $achievement = null;
                }
            } else {
                $reopened = $this->writer->apply($achievement, $input, $this->context->claimableCompetencies($portfolio), $reviewers);

                if ('submit' === $request->request->get('action')) {
                    $errors = $this->writer->submit($achievement);
                }

                $this->entityManager->persist($achievement);
                $this->entityManager->flush();

                // What was written is kept either way; a refused submission comes back to the form
                // of the saved draft, never to « new » again - that would create a second one.
                if ([] !== $errors) {
                    $this->addFlash('warning', 'portfolioAchievementSavedNotSubmittedFlashMessage');
                    foreach ($errors as $error) {
                        $this->addFlash('error', $error);
                    }

                    return $this->redirectToRoute('app_my_portfolio_achievement_edit', ['id' => $achievement->getId()]);
                }

                $this->addFlash('success', match (true) {
                    'submit' === $request->request->get('action') => 'portfolioAchievementSubmittedFlashMessage',
                    $reopened => 'portfolioAchievementReopenedFlashMessage',
                    default => 'portfolioAchievementSavedFlashMessage',
                });

                return $this->redirectToRoute('app_my_portfolio_achievement', ['id' => $achievement->getId()]);
            }
        }

        return $this->render('portfolio/my/achievement_form.html.twig', [
            'portfolio' => $portfolio,
            'achievement' => $achievement,
            'blocks' => $this->context->claimableBlocks($portfolio),
            'reviewers' => $reviewers,
            'errors' => $errors,
            'settings' => PortfolioSetting::cases(),
            'posted' => $request->isMethod('POST') && null === $achievement ? $request->request->all() : null,
        ]);
    }

    #[Route(path: '/my/portfolio/achievements/{id}', name: 'app_my_portfolio_achievement', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $portfolio = $this->myPortfolio($this->context);
        $achievement = $this->myAchievement($portfolio, $id);

        return $this->render('portfolio/my/achievement_show.html.twig', [
            'portfolio' => $portfolio,
            'achievement' => $achievement,
            'evidenceForm' => $this->createForm(PortfolioEvidenceType::class, null, [
                'action' => $this->generateUrl('app_my_portfolio_achievement_evidence', ['id' => $achievement->getId()]),
            ]),
            'submissionErrors' => $this->writer->submissionErrors($achievement),
        ]);
    }

    #[Route(path: '/my/portfolio/achievements/{id}/submit', name: 'app_my_portfolio_achievement_submit', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function submit(int $id, Request $request): Response
    {
        $portfolio = $this->myPortfolio($this->context);
        $achievement = $this->myAchievement($portfolio, $id);
        $this->assertToken($request);

        $errors = $this->writer->submit($achievement);
        $this->entityManager->flush();

        foreach ($errors as $error) {
            $this->addFlash('error', $error);
        }
        if ([] === $errors) {
            $this->addFlash('success', 'portfolioAchievementSubmittedFlashMessage');
        }

        return $this->redirectToRoute('app_my_portfolio_achievement', ['id' => $achievement->getId()]);
    }

    /**
     * A draft never submitted can be thrown away; anything a validateur has seen stays, like the
     * journal it has.
     */
    #[Route(path: '/my/portfolio/achievements/{id}/delete', name: 'app_my_portfolio_achievement_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $portfolio = $this->myPortfolio($this->context);
        $achievement = $this->myAchievement($portfolio, $id);
        $this->assertToken($request);

        if (0 !== $achievement->getRevision()) {
            throw $this->createNotFoundException();
        }

        $portfolio->getAchievements()->removeElement($achievement);
        $this->entityManager->remove($achievement);
        $this->entityManager->flush();
        $this->addFlash('success', 'portfolioAchievementDeletedFlashMessage');

        return $this->redirectToRoute('app_my_portfolio');
    }

    #[Route(path: '/my/portfolio/achievements/{id}/evidence', name: 'app_my_portfolio_achievement_evidence', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addEvidence(int $id, Request $request, PortfolioEvidenceIntake $intake): Response
    {
        $portfolio = $this->myPortfolio($this->context);
        $achievement = $this->myAchievement($portfolio, $id);
        $form = $this->createForm(PortfolioEvidenceType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $before = PortfolioSnapshot::ofAchievement($achievement);
            $evidence = $intake->fromForm($form, $achievement);

            if (\is_string($evidence)) {
                $this->addFlash('error', $evidence);
            } else {
                $this->entityManager->persist($evidence);
                $reopened = $this->writer->afterChange($achievement, $before);
                $this->entityManager->flush();
                $this->addFlash('success', $reopened ? 'portfolioAchievementReopenedFlashMessage' : 'portfolioEvidenceAddedFlashMessage');
            }
        } else {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('error', $error->getMessage());
            }
        }

        return $this->redirectToRoute('app_my_portfolio_achievement', ['id' => $achievement->getId()]);
    }

    #[Route(path: '/my/portfolio/achievements/{id}/evidence/{evidenceId}/remove', name: 'app_my_portfolio_achievement_evidence_remove', requirements: ['id' => '\d+', 'evidenceId' => '\d+'], methods: ['POST'])]
    public function removeEvidence(int $id, int $evidenceId, Request $request): Response
    {
        $portfolio = $this->myPortfolio($this->context);
        $achievement = $this->myAchievement($portfolio, $id);
        $this->assertToken($request);

        foreach ($achievement->getEvidences() as $evidence) {
            if ($evidence->getId() === $evidenceId) {
                $before = PortfolioSnapshot::ofAchievement($achievement);
                $achievement->removeEvidence($evidence);
                $reopened = $this->writer->afterChange($achievement, $before);
                $this->entityManager->flush();
                $this->addFlash('success', $reopened ? 'portfolioAchievementReopenedFlashMessage' : 'portfolioEvidenceRemovedFlashMessage');

                return $this->redirectToRoute('app_my_portfolio_achievement', ['id' => $achievement->getId()]);
            }
        }

        throw $this->createNotFoundException();
    }
}
