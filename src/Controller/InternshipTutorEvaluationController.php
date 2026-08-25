<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipTutorLink;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\UfaActivityType;
use App\Repository\InternshipEvaluationPeriodRepository;
use App\Repository\InternshipLivretEngagementRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Security\Voter\InternshipTutorLinkVoter;
use App\Service\AlternanceEngagementService;
use App\Service\AlternancePeriodChainNotifier;
use App\Service\AlternancePeriodWizardService;
use App\Service\AlternanceTutorWizardStepBuilder;
use App\Service\GotenbergUnavailableException;
use App\Service\InternshipBookletBuilder;
use App\Service\InternshipBookletPdfExporter;
use App\Service\UfaActivityRecorder;
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
#[RequiresFeature(Feature::TutorEvaluations)]
class InternshipTutorEvaluationController extends AbstractController
{
    use ProgramFeatureGuardTrait;

    #[Route(path: '/my/internship', name: 'app_internship_tutor_home')]
    public function home(InternshipTutorLinkRepository $tutorLinkRepository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository, InternshipLivretEngagementRepository $engagementRepository, AlternancePeriodWizardService $wizardService): Response
    {
        $user = $this->currentUser();
        // Only surface links whose Program still has the internship feature turned on - a
        // tutor with links across multiple programs keeps seeing the ones still enabled instead
        // of losing the whole home page over one disabled Program.
        $tutorLinks = array_values(array_filter(
            $tutorLinkRepository->findActiveForTutorUser($user),
            static fn (InternshipTutorLink $tutorLink): bool => $tutorLink->getProgram()->isInternshipManagementEnabled(),
        ));

        // No first-login linking to do any more: staff creating the alternance already created
        // this account and pointed the link at it (App\Service\InternshipTutorProvisioningService),
        // so findActiveForTutorUser() matches on $tutor outright. The test-account flag is set
        // there too, at creation time, rather than being deferred to this first login.

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
                    $tutorSigned = null !== $evaluations['tutorEvaluation']?->getSignedAt();
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
     * signature (amber, first access), which beats an engagement they HAVE signed but whose other
     * signatures are still missing - nothing pending shows the green "vous êtes à jour".
     *
     * That fourth case is the one a plain "nothing to fill" test gets wrong: while the periods
     * are closed (AlternancePeriodWizardService::arePeriodsOpen()) the current period resolves to
     * 'notOpen', so it counts as neither late nor to-fill, and a tutor sitting in the middle of
     * an unopened période 1 was told they were up to date and pointed at the NEXT période.
     */
    private function buildTutorBanner(array $rows): array
    {
        $toFill = [];
        $late = [];
        $engagementPending = null;
        $engagementWaitingOthers = null;
        foreach ($rows as $row) {
            if (null !== $row['current'] && 'toFill' === $row['current']['state']) {
                $toFill[] = $row;
            }
            if (null !== $row['current'] && 'late' === $row['current']['state']) {
                $late[] = $row;
            }
            if (!$row['tutorSignedEngagement']) {
                $engagementPending ??= $row;
                continue;
            }
            if (!$row['periodsOpen']) {
                $engagementWaitingOthers ??= $row;
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

        if (null !== $engagementWaitingOthers) {
            return ['type' => 'waitingEngagement', 'row' => $engagementWaitingOthers];
        }

        return ['type' => 'upToDate'];
    }

    // The tutor's own 4-step guided evaluation (28a-28d) - replaces the older single flat-form
    // app_internship_tutor_evaluate route. Staff's "view/act on behalf" equivalent is
    // Ufa\PeriodWizardController::periodTuteur(); both share AlternanceTutorWizardStepBuilder.
    #[Route(path: '/my/internship/{tutorLinkId}/{periodId}/{step}', name: 'app_internship_tutor_period_step', requirements: ['tutorLinkId' => '\d+', 'periodId' => '\d+', 'step' => 'comportement|competences|forces|remarques'])]
    public function periodStep(int $tutorLinkId, int $periodId, string $step, Request $request, EntityManagerInterface $entityManager, InternshipTutorLinkRepository $tutorLinkRepository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository, AlternancePeriodWizardService $wizardService, AlternanceTutorWizardStepBuilder $stepBuilder, AlternancePeriodChainNotifier $chainNotifier, UfaActivityRecorder $activityRecorder, TranslatorInterface $translator): Response
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
                // Before signing: it is the move from unsigned to signed that warns the apprentice,
                // not the fact of being signed - without which a re-save would chase them again.
                $wasSigned = $evaluation->isSigned();
                $evaluation->setValidationDate(new \DateTimeImmutable());
                $evaluation->setLastEditedBy($this->currentUser());
                if ('sign' === $request->request->get('action')) {
                    $evaluation->setSignedAt(new \DateTimeImmutable());
                    $evaluation->setSignedBy($this->currentUser());
                }
                $this->stampAuditFields($evaluation, null !== $evaluation->getCreatedBy());

                $entityManager->persist($evaluation);
                $entityManager->flush();

                if (!$wasSigned && $evaluation->isSigned()) {
                    $chainNotifier->notifyStudentAfterTutorSignature($tutorLink, $evaluationPeriod);
                    $activityRecorder->record(UfaActivityType::PeriodTutorSigned, $tutorLink, $this->currentUser(), $evaluationPeriod);
                }

                $nextStep = $stepBuilder->nextStep($step);
                if ('sign' === $request->request->get('action') && null === $nextStep) {
                    $this->addFlash('success', 'internshipTutorEvaluationSavedFlashMessage');

                    return $this->redirectToRoute('app_internship_tutor_home');
                }

                // Saved either way - the work already typed is never thrown away - but staying on
                // this step until every one of its fields is answered.
                if (null !== $nextStep && !$stepBuilder->isStepComplete($step, $evaluation)) {
                    $this->addFlash('error', 'ufaAlternanceWizardStepIncompleteFlashMessage');

                    $nextStep = null;
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
    // the staff side, see Ufa\EngagementController::engagementSign().
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

    // The tutor's own reader for the booklet - the same TOC-plus-iframe shell staff get from
    // Ufa\BookletController::livret(), rather than the bare document that used to be served
    // here (no navbar, no way back).
    #[Route(path: '/my/internship/{tutorLinkId}/booklet', name: 'app_internship_tutor_booklet')]
    public function booklet(int $tutorLinkId, InternshipTutorLinkRepository $tutorLinkRepository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository): Response
    {
        $tutorLink = $this->findBookletTutorLinkOrDeny($tutorLinkId, $tutorLinkRepository);

        return $this->render('internship_tutor/booklet.html.twig', [
            'tutorLink' => $tutorLink,
            'periods' => $evaluationPeriodRepository->findAllActiveForProgram($tutorLink->getProgram()),
        ]);
    }

    // Unwrapped document behind the reader's <iframe src="..."> - the tutor's counterpart to
    // Ufa\BookletController::livretFrame().
    #[Route(path: '/my/internship/{tutorLinkId}/booklet/frame', name: 'app_internship_tutor_booklet_frame')]
    public function bookletFrame(int $tutorLinkId, InternshipTutorLinkRepository $tutorLinkRepository, InternshipBookletBuilder $bookletBuilder): Response
    {
        $tutorLink = $this->findBookletTutorLinkOrDeny($tutorLinkId, $tutorLinkRepository);

        return $this->render('internship/booklet.html.twig', $bookletBuilder->build($tutorLink));
    }

    private function findBookletTutorLinkOrDeny(int $tutorLinkId, InternshipTutorLinkRepository $tutorLinkRepository): InternshipTutorLink
    {
        $tutorLink = $tutorLinkRepository->find($tutorLinkId) ?? throw $this->createNotFoundException();
        // Viewing the booklet is a strict subset of what evaluating already grants - same Voter
        // check as evaluate(), no new attribute needed.
        $this->denyAccessUnlessGranted(InternshipTutorLinkVoter::EVALUATE, $tutorLink);
        $this->assertProgramFeatureEnabled($tutorLink->getProgram()->isInternshipManagementEnabled());

        return $tutorLink;
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

            // Back to the reader on failure - that is where this button lives, and unlike the
            // bare document it used to sit next to, it has a flash-message region to show the
            // error in.
            return $this->redirectToRoute('app_internship_tutor_booklet', ['tutorLinkId' => $tutorLink->getId()]);
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
