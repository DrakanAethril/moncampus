<?php

declare(strict_types=1);

namespace App\Controller\Ufa;

use App\Repository\InternshipTutorLinkRepository;
use App\Service\AlternanceEngagementService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * L'engagement tripartite : consultation et signature.
 *
 * Split out of the former UfaAlternanceController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD") or is_granted("ROLE_TEACHER")'))]
class EngagementController extends AbstractController
{
    use UfaAlternanceTrait;

    // Staff view of the engagement (27b) - can view all 3 signature states and sign only the
    // centre-representative box; the tutor's and student's own signatures always come from their
    // own self-service routes (InternshipTutorEvaluationController::engagement() /
    // ProgramInternshipEvaluationController::myEngagement()), never on their behalf here.
    #[Route(path: '/ufa/alternances/{id}/engagement', name: 'app_ufa_alternance_engagement', requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression(self::STAFF_ACCESS_EXPRESSION))]
    public function engagement(int $id, InternshipTutorLinkRepository $tutorLinkRepository, AlternanceEngagementService $engagementService): Response
    {
        $tutorLink = $tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();
        $engagement = $engagementService->findOrCreate($tutorLink);

        return $this->render('ufa/alternance/engagement.html.twig', [
            'tutorLink' => $tutorLink,
            'engagement' => $engagement,
        ]);
    }

    #[Route(path: '/ufa/alternances/{id}/engagement/sign', name: 'app_ufa_alternance_engagement_sign', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression(self::STAFF_ACCESS_EXPRESSION))]
    public function engagementSign(int $id, Request $request, InternshipTutorLinkRepository $tutorLinkRepository, AlternanceEngagementService $engagementService): Response
    {
        $tutorLink = $tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();
        $this->assertValidFormToken('ufa_alternance_engagement_sign', $request);
        $engagement = $engagementService->findOrCreate($tutorLink);

        try {
            $engagementService->signAsCenter($engagement, $this->currentUser());
            $this->addFlash('success', 'ufaAlternanceEngagementSignedFlashMessage');
        } catch (\DomainException) {
            $this->addFlash('error', 'ufaAlternanceEngagementSignBlockedFlashMessage');
        }

        return $this->redirectToRoute('app_ufa_alternance_engagement', ['id' => $tutorLink->getId()]);
    }
}
