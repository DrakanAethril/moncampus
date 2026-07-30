<?php

namespace App\Controller;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipTutorLink;
use App\Entity\User;
use App\Repository\InternshipEvaluationPeriodRepository;
use App\Repository\InternshipLivretEngagementRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Security\Voter\InternshipTutorLinkVoter;
use App\Service\AlternanceEngagementService;
use App\Service\AlternancePeriodWizardService;
use App\Service\AlternanceTutorWizardStepBuilder;
use App\Service\GotenbergUnavailableException;
use App\Service\InternshipBookletBuilder;
use App\Service\InternshipBookletPdfExporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

// The entreprise tutor's own area (ROLE_TUTOR, from the LDAP "tutor" group) - deliberately
// outside the staff/student layout/app.html.twig shell (see templates/layout/tutor.html.twig),
// since a tutor has no use for the Section/Program/Paramètres navigation built for staff and
// students. Used to key off ROLE_EXTERNAL, which turned out too generic once other outside
// populations needed accounts too.
#[IsGranted('ROLE_TUTOR')]
class InternshipTutorEvaluationController extends AbstractController
{
    use ProgramFeatureGuardTrait;

    #[Route(path: '/my/internship', name: 'app_internship_tutor_home')]
    public function home(EntityManagerInterface $entityManager, InternshipTutorLinkRepository $tutorLinkRepository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository, InternshipLivretEngagementRepository $engagementRepository, AlternancePeriodWizardService $wizardService): Response
    {
        $user = $this->currentUser();
        // Only surface links whose Program still has the internship feature turned on - a
        // tutor with links across multiple programs keeps seeing the ones still enabled instead
        // of losing the whole home page over one disabled Program.
        $tutorLinks = array_values(array_filter(
            $tutorLinkRepository->findActiveForTutorUser($user),
            static fn (InternshipTutorLink $tutorLink): bool => $tutorLink->getProgram()->isInternshipManagementEnabled(),
        ));

        // Opportunistic first-login linking: a link matched only by tutorEmail or by the login
        // generated for its spawned LdapManageUser request (the LDAP "tutor" account didn't
        // exist yet when staff created the link - see InternshipTutorLinkRepository::
        // findActiveForTutorUser()) gets attached to this now-authenticated User once and for all.
        $linked = false;
        foreach ($tutorLinks as $tutorLink) {
            if (null === $tutorLink->getTutor()) {
                $tutorLink->setTutor($user);
                $linked = true;
            }
        }
        if ($linked) {
            $entityManager->flush();
        }

        // Per-alternant card state (design_handoff_dashboards 35a-35d): each link resolves its
        // engagement gate, its "current" period with the 4-role chain, and its closed past
        // periods. Each tutor link's Program has its own evaluation periods, so all of this is
        // resolved per-link.
        $today = new \DateTimeImmutable('today');
        $rows = [];
        foreach ($tutorLinks as $tutorLink) {
            $engagement = $engagementRepository->findOneForTutorLink($tutorLink);
            $tutorSignedEngagement = null !== $engagement && null !== $engagement->getSignedTutorAt();
            $periodsOpen = $wizardService->arePeriodsOpen($tutorLink);

            $current = null;
            $next = null;
            $pastPeriods = [];
            foreach ($evaluationPeriodRepository->findAllActiveForProgram($tutorLink->getProgram()) as $period) {
                if ($wizardService->isPeriodClosed($tutorLink, $period)) {
                    $evaluations = $wizardService->evaluationsFor($tutorLink, $period);
                    $pastPeriods[] = ['period' => $period, 'closedAt' => $evaluations['supervisorEvaluation']?->getClosedAt()];
                    continue;
                }

                if ($period->getStartDate() > $today) {
                    $next ??= $period;
                    continue;
                }

                if (null === $current) {
                    $evaluations = $wizardService->evaluationsFor($tutorLink, $period);
                    $tutorSigned = null !== ($evaluations['tutorEvaluation']?->getSignedAt());
                    $current = [
                        'period' => $period,
                        ...$evaluations,
                        'state' => match (true) {
                            !$periodsOpen => 'notOpen',
                            $tutorSigned => 'waitingOthers',
                            $period->getEndDate() < $today => 'late',
                            default => 'toFill',
                        },
                    ];
                }
            }

            $rows[] = [
                'tutorLink' => $tutorLink,
                'engagement' => $engagement,
                'tutorSignedEngagement' => $tutorSignedEngagement,
                'periodsOpen' => $periodsOpen,
                'current' => $current,
                'next' => $next,
                'pastPeriods' => $pastPeriods,
            ];
        }

        return $this->render('internship_tutor/home.html.twig', [
            'rows' => $rows,
            'banner' => $this->buildTutorBanner($rows),
            'nextPeriod' => array_reduce($rows, static fn (?InternshipEvaluationPeriod $carry, array $row): ?InternshipEvaluationPeriod => $carry ?? $row['next'], null),
        ]);
    }

    /**
     * One banner, most urgent first (35a-35d): a late evaluation (red, the alternant is blocked)
     * beats an open one (amber), which beats the engagement still awaiting the tutor's own
     * signature (amber, first access) - nothing pending shows the green "vous êtes à jour".
     */
    private function buildTutorBanner(array $rows): array
    {
        $toFill = [];
        $late = [];
        $engagementPending = null;
        foreach ($rows as $row) {
            if (null !== $row['current'] && 'toFill' === $row['current']['state']) {
                $toFill[] = $row;
            }
            if (null !== $row['current'] && 'late' === $row['current']['state']) {
                $late[] = $row;
            }
            if (!$row['tutorSignedEngagement']) {
                $engagementPending ??= $row;
            }
        }

        if ([] !== $late) {
            return ['type' => 'late', 'count' => \count($late) + \count($toFill), 'row' => $late[0]];
        }

        if ([] !== $toFill) {
            return ['type' => 'toFill', 'count' => \count($toFill), 'row' => $toFill[0]];
        }

        if (null !== $engagementPending) {
            return ['type' => 'engagement', 'row' => $engagementPending];
        }

        return ['type' => 'upToDate'];
    }

    // The tutor's own 4-step guided evaluation (28a-28d) - replaces the older single flat-form
    // app_internship_tutor_evaluate route. Staff's "view/act on behalf" equivalent is
    // UfaAlternanceController::periodTuteur(); both share AlternanceTutorWizardStepBuilder.
    #[Route(path: '/my/internship/{tutorLinkId}/{periodId}/{step}', name: 'app_internship_tutor_period_step', requirements: ['tutorLinkId' => '\d+', 'periodId' => '\d+', 'step' => 'comportement|competences|forces|remarques'])]
    public function periodStep(int $tutorLinkId, int $periodId, string $step, Request $request, EntityManagerInterface $entityManager, InternshipTutorLinkRepository $tutorLinkRepository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository, AlternancePeriodWizardService $wizardService, AlternanceTutorWizardStepBuilder $stepBuilder, TranslatorInterface $translator): Response
    {
        $tutorLink = $tutorLinkRepository->find($tutorLinkId) ?? throw $this->createNotFoundException();
        $evaluationPeriod = $evaluationPeriodRepository->find($periodId) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(InternshipTutorLinkVoter::EVALUATE, $tutorLink);
        $this->assertProgramFeatureEnabled($tutorLink->getProgram()->isInternshipManagementEnabled());

        if (!$wizardService->arePeriodsOpen($tutorLink)) {
            $this->addFlash('warning', 'ufaAlternanceWizardPeriodsNotOpenFlashMessage');

            return $this->redirectToRoute('app_internship_tutor_engagement', ['tutorLinkId' => $tutorLink->getId()]);
        }

        $evaluation = $stepBuilder->findOrPrepare($tutorLink, $evaluationPeriod);
        $readOnly = $wizardService->isTutorStepReadOnly($tutorLink, $evaluationPeriod);
        $form = $stepBuilder->buildStepForm($step, $evaluation, $tutorLink->getProgram());

        if (!$readOnly) {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $evaluation->setValidationDate(new \DateTimeImmutable());
                $evaluation->setLastEditedBy($this->currentUser());
                if ('sign' === $request->request->get('action')) {
                    $evaluation->setSignedAt(new \DateTimeImmutable());
                    $evaluation->setSignedBy($this->currentUser());
                }
                $this->stampAuditFields($evaluation, null !== $evaluation->getCreatedBy());

                $entityManager->persist($evaluation);
                $entityManager->flush();

                $nextStep = $stepBuilder->nextStep($step);
                if ('sign' === $request->request->get('action') && null === $nextStep) {
                    $this->addFlash('success', 'internshipTutorEvaluationSavedFlashMessage');

                    return $this->redirectToRoute('app_internship_tutor_home');
                }

                return $this->redirectToRoute('app_internship_tutor_period_step', ['tutorLinkId' => $tutorLink->getId(), 'periodId' => $evaluationPeriod->getId(), 'step' => $nextStep ?? $step]);
            }
        }

        return $this->render('internship_tutor/period_step.html.twig', [
            'tutorLink' => $tutorLink,
            'period' => $evaluationPeriod,
            'step' => $step,
            'form' => $form,
            ...$wizardService->evaluationsFor($tutorLink, $evaluationPeriod),
            'tutorEvaluation' => $evaluation,
            'readOnly' => $readOnly,
            'backPath' => $stepBuilder->previousStep($step) ? $this->generateUrl('app_internship_tutor_period_step', ['tutorLinkId' => $tutorLink->getId(), 'periodId' => $evaluationPeriod->getId(), 'step' => $stepBuilder->previousStep($step)]) : null,
            'stepLabels' => array_map(static fn (string $s): string => $translator->trans($stepBuilder->stepLabel($s)), AlternanceTutorWizardStepBuilder::STEPS),
            'currentStepIndex' => array_search($step, AlternanceTutorWizardStepBuilder::STEPS, true) + 1,
            'helperText' => $translator->trans('ufaAlternanceWizardTuteurNoIntermediateSaveHelpText'),
            'signLabel' => $translator->trans('ufaAlternanceWizardTuteurSignButtonLabel'),
            'showSaveButton' => false,
        ]);
    }

    // The tutor's own signature on the "mise à disposition du livret" gate (27b) - the centre
    // representative's own signature (which opens the evaluation periods) only ever happens from
    // the staff side, see UfaAlternanceController::engagementSign().
    #[Route(path: '/my/internship/{tutorLinkId}/engagement', name: 'app_internship_tutor_engagement', requirements: ['tutorLinkId' => '\d+'])]
    public function engagement(int $tutorLinkId, InternshipTutorLinkRepository $tutorLinkRepository, AlternanceEngagementService $engagementService): Response
    {
        $tutorLink = $tutorLinkRepository->find($tutorLinkId) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(InternshipTutorLinkVoter::EVALUATE, $tutorLink);
        $this->assertProgramFeatureEnabled($tutorLink->getProgram()->isInternshipManagementEnabled());

        return $this->render('internship_tutor/engagement.html.twig', [
            'tutorLink' => $tutorLink,
            'engagement' => $engagementService->findOrCreate($tutorLink),
        ]);
    }

    #[Route(path: '/my/internship/{tutorLinkId}/engagement/sign', name: 'app_internship_tutor_engagement_sign', methods: ['POST'], requirements: ['tutorLinkId' => '\d+'])]
    public function engagementSign(int $tutorLinkId, Request $request, InternshipTutorLinkRepository $tutorLinkRepository, AlternanceEngagementService $engagementService): Response
    {
        $tutorLink = $tutorLinkRepository->find($tutorLinkId) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(InternshipTutorLinkVoter::EVALUATE, $tutorLink);
        $this->assertValidFormToken('internship_tutor_engagement_sign', $request);

        $engagementService->signAsTutor($engagementService->findOrCreate($tutorLink), $this->currentUser());
        $this->addFlash('success', 'ufaAlternanceEngagementSignedFlashMessage');

        return $this->redirectToRoute('app_internship_tutor_engagement', ['tutorLinkId' => $tutorLink->getId()]);
    }

    #[Route(path: '/my/internship/{tutorLinkId}/booklet', name: 'app_internship_tutor_booklet')]
    public function booklet(int $tutorLinkId, InternshipTutorLinkRepository $tutorLinkRepository, InternshipBookletBuilder $bookletBuilder): Response
    {
        $tutorLink = $tutorLinkRepository->find($tutorLinkId) ?? throw $this->createNotFoundException();
        // Viewing the booklet is a strict subset of what evaluating already grants - same Voter
        // check as evaluate(), no new attribute needed.
        $this->denyAccessUnlessGranted(InternshipTutorLinkVoter::EVALUATE, $tutorLink);
        $this->assertProgramFeatureEnabled($tutorLink->getProgram()->isInternshipManagementEnabled());

        return $this->render('internship/booklet.html.twig', $bookletBuilder->build($tutorLink));
    }

    #[Route(path: '/my/internship/{tutorLinkId}/booklet/pdf', name: 'app_internship_tutor_booklet_pdf')]
    public function bookletPdf(int $tutorLinkId, InternshipTutorLinkRepository $tutorLinkRepository, InternshipBookletPdfExporter $exporter): Response
    {
        $tutorLink = $tutorLinkRepository->find($tutorLinkId) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(InternshipTutorLinkVoter::EVALUATE, $tutorLink);
        $this->assertProgramFeatureEnabled($tutorLink->getProgram()->isInternshipManagementEnabled());

        try {
            $pdf = $exporter->export($tutorLink, $this->renderView(...));
        } catch (GotenbergUnavailableException) {
            $this->addFlash('error', 'internshipBookletPdfExportFailedFlashMessage');

            // Redirects to the home list (not the booklet "View" route) on failure -
            // internship/booklet.html.twig extends base.html.twig directly with no flash-message
            // region, so an error flash set there would never actually be shown to the user.
            return $this->redirectToRoute('app_internship_tutor_home');
        }

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, sprintf('livret-alternant-%s.pdf', $tutorLink->getStudent()->getUsername())),
        ]);
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    private function stampAuditFields(object $entity, bool $isEdit): void
    {
        if ($isEdit) {
            $entity->setLastUpdatedBy($this->currentUser());
            $entity->setLastUpdatedDate(new \DateTimeImmutable());
        } else {
            $entity->setCreatedBy($this->currentUser());
        }
    }

    // For plain <form method="post"> submissions - the token travels as a body field (name="_token").
    private function assertValidFormToken(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
