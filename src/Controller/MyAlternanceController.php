<?php

namespace App\Controller;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\Program;
use App\Entity\User;
use App\Repository\InternshipEvaluationPeriodRepository;
use App\Repository\InternshipLivretEngagementRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Service\AlternancePeriodWizardService;
use App\Service\StudentAlternanceProgramResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The student's "Mon alternance" page (design_handoff_dashboards etu-d) - a top-level view of the
 * whole alternance (periods with the 4-role chain, contract, engagement, livret access) reusing
 * the same gates as the per-program wizard in ProgramInternshipEvaluationController, which stays
 * the actual step-by-step journey this page links into.
 */
class MyAlternanceController extends AbstractController
{
    #[Route(path: '/my/alternance', name: 'app_my_alternance')]
    #[IsGranted('ROLE_STUDENT')]
    public function __invoke(
        InternshipTutorLinkRepository $tutorLinkRepository,
        InternshipEvaluationPeriodRepository $evaluationPeriodRepository,
        InternshipLivretEngagementRepository $engagementRepository,
        AlternancePeriodWizardService $wizardService,
        StudentAlternanceProgramResolver $alternanceProgramResolver,
    ): Response {
        /** @var User $student */
        $student = $this->getUser();

        // Same resolver as the navbar tab that leads here - a student who is not tagged with
        // their class's alternance modality is not an alternant, and never saw the tab either.
        $program = $alternanceProgramResolver->resolve($student);

        if (null === $program) {
            throw $this->createNotFoundException();
        }

        $tutorLink = $tutorLinkRepository->findOneForStudentAndProgram($student, $program);

        if (null === $tutorLink) {
            // Alternance feature enabled on the Program but no contract on file yet for this
            // student - show the page frame with an empty state rather than a bare 404, since
            // the navbar tab already brought them here.
            return $this->render('my_alternance/index.html.twig', [
                'program' => $program,
                'tutorLink' => null,
                'periods' => [],
                'engagement' => null,
                'banner' => null,
                'tutorFeedback' => null,
            ]);
        }

        $engagement = $engagementRepository->findOneForTutorLink($tutorLink);
        $periodsOpen = $wizardService->arePeriodsOpen($tutorLink);
        $today = new \DateTimeImmutable('today');

        $periods = [];
        $yourTurnPeriod = null;
        $tutorFeedback = null;
        foreach ($evaluationPeriodRepository->findAllActiveForProgram($program) as $period) {
            $evaluations = $wizardService->evaluationsFor($tutorLink, $period);
            $studentSigned = null !== $evaluations['studentEvaluation']?->getSignedAt();
            $closed = $wizardService->isPeriodClosed($tutorLink, $period);
            $yourTurn = !$closed && !$studentSigned && $wizardService->isStudentStepOpen($tutorLink, $period);

            // "Non ouverte" only while truly locked (engagement gate or future start) - a running
            // period the tutor is still filling in shows "En cours", matching its chain chips.
            $status = match (true) {
                $closed => 'closed',
                $yourTurn => 'yourTurn',
                !$periodsOpen, $period->getStartDate() > $today => 'notOpen',
                default => 'inProgress',
            };

            $periods[] = ['period' => $period, 'status' => $status, ...$evaluations];

            if ('yourTurn' === $status && null === $yourTurnPeriod) {
                $yourTurnPeriod = ['period' => $period, ...$evaluations];
            }

            // "Ce que mon tuteur a écrit au Point N" - the most recent period whose tutor
            // evaluation is signed (periods come back in chronological order).
            $tutorEvaluation = $evaluations['tutorEvaluation'];
            if (null !== $tutorEvaluation && null !== $tutorEvaluation->getSignedAt()
                && (null !== $tutorEvaluation->getStrengthsText() || null !== $tutorEvaluation->getGoalsText())) {
                $tutorFeedback = ['period' => $period, 'evaluation' => $tutorEvaluation];
            }
        }

        return $this->render('my_alternance/index.html.twig', [
            'program' => $program,
            'tutorLink' => $tutorLink,
            'periods' => $periods,
            'engagement' => $engagement,
            'banner' => $this->buildBanner($program, $yourTurnPeriod, $engagement),
            'tutorFeedback' => $tutorFeedback,
        ]);
    }

    /**
     * One banner, the most urgent thing only (dashboard grammar §1.2): answering an open
     * evaluation beats signing the engagement; nothing to do means no banner at all.
     *
     * @param array{period: InternshipEvaluationPeriod, tutorEvaluation: mixed}|null $yourTurnPeriod
     */
    private function buildBanner(Program $program, ?array $yourTurnPeriod, ?object $engagement): ?array
    {
        if (null !== $yourTurnPeriod) {
            return [
                'type' => 'yourTurn',
                'period' => $yourTurnPeriod['period'],
                'tutorSignedAt' => $yourTurnPeriod['tutorEvaluation']?->getSignedAt(),
            ];
        }

        if (null !== $engagement && null === $engagement->getSignedStudentAt()) {
            return ['type' => 'engagement'];
        }

        return null;
    }
}
