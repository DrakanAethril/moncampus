<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipStudentEvaluation;
use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\UfaActivityType;
use App\Form\InternshipStudentEvaluationType;
use App\Repository\InternshipEvaluationPeriodRepository;
use App\Repository\InternshipStudentEvaluationRepository;
use App\Repository\InternshipTutorEvaluationRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Repository\ProgramRepository;
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

// A student's own Livret Alternant self-evaluation - route-level guards (not a class-level
// staff gate) since students, not staff, are the ones reaching this area. Stays inside the
// normal layout/app.html.twig shell - students already navigate their program from there.
class ProgramInternshipEvaluationController extends AbstractController
{
    use ProgramFeatureGuardTrait;

    #[Route(path: '/programs/{id}/internship/my-evaluations', name: 'app_program_internship_my_evaluations')]
    #[IsGranted('ROLE_STUDENT')]
    public function myEvaluations(int $id, ProgramRepository $repository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository, InternshipStudentEvaluationRepository $evaluationRepository, InternshipTutorLinkRepository $tutorLinkRepository, InternshipTutorEvaluationRepository $tutorEvaluationRepository): Response
    {
        $program = $this->findProgramForStudentOrNotFound($id, $repository);
        $tutorLink = $tutorLinkRepository->findOneForStudentAndProgram($this->currentUser(), $program);

        $evaluationsByPeriodId = [];
        foreach ($evaluationRepository->findAllForStudentAndProgram($this->currentUser(), $program) as $evaluation) {
            $evaluationsByPeriodId[$evaluation->getEvaluationPeriod()->getId()] = $evaluation;
        }

        // A student can only fill in their own evaluation for a period once the tutor has
        // submitted theirs for that same period - see myEvaluation() below for the actual gate;
        // this is only computed here to reflect that state in the list (locked row, no action).
        $tutorSubmittedPeriodIds = [];
        if (null !== $tutorLink) {
            foreach ($tutorEvaluationRepository->findAllForTutorLink($tutorLink) as $tutorEvaluation) {
                $tutorSubmittedPeriodIds[$tutorEvaluation->getEvaluationPeriod()->getId()] = true;
            }
        }

        $rows = array_map(
            static fn (InternshipEvaluationPeriod $evaluationPeriod): array => [
                'period' => $evaluationPeriod,
                'submitted' => isset($evaluationsByPeriodId[$evaluationPeriod->getId()]),
                'tutorSubmitted' => isset($tutorSubmittedPeriodIds[$evaluationPeriod->getId()]),
            ],
            $evaluationPeriodRepository->findAllActiveForProgram($program),
        );

        return $this->render('program/internship_my_evaluations.html.twig', [
            'program' => $program,
            'rows' => $rows,
            // The "view my booklet" button only makes sense once a tutor contract is on file -
            // same guard as myBooklet() itself, checked here just to decide whether to show it.
            'hasTutorLink' => null !== $tutorLink,
        ]);
    }

    // The alternant's own guided journey (29a-29d) - steps 1-3 render the tutor's evaluation
    // read-only, step 4 is the student's own remarksText + signature. Replaces the older single
    // flat-form app_program_internship_my_evaluation route. Staff's "view/act on behalf"
    // equivalent is Ufa\PeriodWizardController::periodAlternant().
    #[Route(path: '/programs/{id}/internship/my-evaluations/{periodId}/{step}', name: 'app_program_internship_my_evaluation_step', requirements: ['periodId' => '\d+', 'step' => 'comportement|competences|forces|remarques'])]
    #[IsGranted('ROLE_STUDENT')]
    public function myEvaluationStep(int $id, int $periodId, string $step, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository, InternshipStudentEvaluationRepository $evaluationRepository, InternshipTutorLinkRepository $tutorLinkRepository, InternshipTutorEvaluationRepository $tutorEvaluationRepository, AlternancePeriodWizardService $wizardService, AlternancePeriodChainNotifier $chainNotifier, UfaActivityRecorder $activityRecorder, TranslatorInterface $translator): Response
    {
        $program = $this->findProgramForStudentOrNotFound($id, $repository);
        $evaluationPeriod = $evaluationPeriodRepository->find($periodId) ?? throw $this->createNotFoundException();
        $student = $this->currentUser();

        $tutorLink = $tutorLinkRepository->findOneForStudentAndProgram($student, $program) ?? throw $this->createNotFoundException();
        if (!$wizardService->isStudentStepOpen($tutorLink, $evaluationPeriod)) {
            $this->addFlash('warning', 'internshipStudentEvaluationLockedFlashMessage');

            return $this->redirectToRoute('app_program_internship_my_evaluations', ['id' => $program->getId()]);
        }

        $tutorEvaluation = $tutorEvaluationRepository->findOneForTutorLinkAndEvaluationPeriod($tutorLink, $evaluationPeriod);
        $evaluation = $evaluationRepository->findOneForStudentAndEvaluationPeriod($student, $evaluationPeriod) ?? new InternshipStudentEvaluation($student, $program, $evaluationPeriod);
        $readOnly = $wizardService->isStudentStepReadOnly($tutorLink, $evaluationPeriod);

        $form = $this->createForm(InternshipStudentEvaluationType::class, $evaluation);

        if ('remarques' === $step && !$readOnly) {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                // See InternshipTutorEvaluationController::periodStep(): only the transition to
                // "signed" triggers the notice to the next role.
                $wasSigned = $evaluation->isSigned();
                $evaluation->setValidationDate(new \DateTimeImmutable());
                $evaluation->setLastEditedBy($student);
                $evaluation->setSignedAt(new \DateTimeImmutable());
                $evaluation->setSignedBy($student);
                $this->stampAuditFields($evaluation, null !== $evaluation->getCreatedBy());

                $entityManager->persist($evaluation);
                $entityManager->flush();

                if (!$wasSigned) {
                    $chainNotifier->notifyReferentTeachersAfterStudentSignature($tutorLink, $evaluationPeriod);
                    $activityRecorder->record(UfaActivityType::PeriodStudentSigned, $tutorLink, $student, $evaluationPeriod);
                }

                $this->addFlash('success', 'internshipStudentEvaluationSavedFlashMessage');

                return $this->redirectToRoute('app_program_internship_my_evaluations', ['id' => $program->getId()]);
            }
        }

        $steps = AlternanceTutorWizardStepBuilder::STEPS;
        $stepIndex = array_search($step, $steps, true);

        return $this->render('program/internship_my_period_step.html.twig', [
            'tutorLink' => $tutorLink,
            'period' => $evaluationPeriod,
            'step' => $step,
            'form' => $form,
            ...$wizardService->evaluationsFor($tutorLink, $evaluationPeriod),
            'tutorEvaluation' => $tutorEvaluation,
            'studentEvaluation' => $evaluation,
            'readOnly' => $readOnly,
            'backPath' => $stepIndex > 0 ? $this->generateUrl('app_program_internship_my_evaluation_step', ['id' => $program->getId(), 'periodId' => $evaluationPeriod->getId(), 'step' => $steps[$stepIndex - 1]]) : null,
            'stepLabels' => [
                $translator->trans('ufaAlternanceWizardStepComportementLabel'),
                $translator->trans('ufaAlternanceWizardStepCompetencesLabel'),
                $translator->trans('ufaAlternanceWizardStepStrengthsLabel'),
                $translator->trans('ufaAlternanceWizardStepAlternantRemarquesLabel'),
            ],
            'currentStepIndex' => $stepIndex + 1,
        ]);
    }

    // The student's own signature on the "mise à disposition du livret" gate (27b) - reachable
    // even if no InternshipTutorLink is on file yet (the invite email is sent as soon as the
    // alternance is created, before any evaluation exists), so this deliberately doesn't call
    // findOneForStudentAndProgram()-and-404 the way myBooklet() does.
    #[Route(path: '/programs/{id}/internship/my-evaluations/engagement', name: 'app_program_internship_my_engagement')]
    #[IsGranted('ROLE_STUDENT')]
    public function myEngagement(int $id, ProgramRepository $repository, InternshipTutorLinkRepository $tutorLinkRepository, AlternanceEngagementService $engagementService): Response
    {
        $program = $this->findProgramForStudentOrNotFound($id, $repository);
        $tutorLink = $tutorLinkRepository->findOneForStudentAndProgram($this->currentUser(), $program) ?? throw $this->createNotFoundException();

        return $this->render('program/internship_my_engagement.html.twig', [
            'program' => $program,
            'tutorLink' => $tutorLink,
            'engagement' => $engagementService->findOrCreate($tutorLink),
        ]);
    }

    #[Route(path: '/programs/{id}/internship/my-evaluations/engagement/sign', name: 'app_program_internship_my_engagement_sign', methods: ['POST'])]
    #[IsGranted('ROLE_STUDENT')]
    public function myEngagementSign(int $id, Request $request, ProgramRepository $repository, InternshipTutorLinkRepository $tutorLinkRepository, AlternanceEngagementService $engagementService): Response
    {
        $program = $this->findProgramForStudentOrNotFound($id, $repository);
        $tutorLink = $tutorLinkRepository->findOneForStudentAndProgram($this->currentUser(), $program) ?? throw $this->createNotFoundException();
        $this->assertValidFormToken('program_internship_my_engagement_sign', $request);

        $engagementService->signAsStudent($engagementService->findOrCreate($tutorLink), $this->currentUser());
        $this->addFlash('success', 'ufaAlternanceEngagementSignedFlashMessage');

        return $this->redirectToRoute('app_program_internship_my_engagement', ['id' => $program->getId()]);
    }

    // The alternant's own reader for the booklet - the same TOC-plus-iframe shell staff and tutors
    // get (Ufa\BookletController::livret(), InternshipTutorEvaluationController::booklet()),
    // rather than the bare document that used to be served here.
    #[Route(path: '/programs/{id}/internship/my-evaluations/booklet', name: 'app_program_internship_my_booklet')]
    #[IsGranted('ROLE_STUDENT')]
    public function myBooklet(int $id, ProgramRepository $repository, InternshipTutorLinkRepository $tutorLinkRepository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository): Response
    {
        $program = $this->findProgramForStudentOrNotFound($id, $repository);
        $tutorLink = $this->findMyTutorLinkOrNotFound($program, $tutorLinkRepository);

        return $this->render('my_alternance/booklet.html.twig', [
            'program' => $program,
            'tutorLink' => $tutorLink,
            'periods' => $evaluationPeriodRepository->findAllActiveForProgram($program),
        ]);
    }

    // Unwrapped document behind the reader's <iframe src="...">.
    #[Route(path: '/programs/{id}/internship/my-evaluations/booklet/frame', name: 'app_program_internship_my_booklet_frame')]
    #[IsGranted('ROLE_STUDENT')]
    public function myBookletFrame(int $id, ProgramRepository $repository, InternshipTutorLinkRepository $tutorLinkRepository, InternshipBookletBuilder $bookletBuilder): Response
    {
        $program = $this->findProgramForStudentOrNotFound($id, $repository);

        return $this->render('internship/booklet.html.twig', $bookletBuilder->build($this->findMyTutorLinkOrNotFound($program, $tutorLinkRepository)));
    }

    // No tutor contract on file yet means no booklet to show, rather than a partially-built page -
    // the same "nothing to reach yet" shape as any other missing-resource 404 here.
    private function findMyTutorLinkOrNotFound(Program $program, InternshipTutorLinkRepository $tutorLinkRepository): InternshipTutorLink
    {
        return $tutorLinkRepository->findOneForStudentAndProgram($this->currentUser(), $program) ?? throw $this->createNotFoundException();
    }

    #[Route(path: '/programs/{id}/internship/my-evaluations/booklet/pdf', name: 'app_program_internship_my_booklet_pdf')]
    #[IsGranted('ROLE_STUDENT')]
    public function myBookletPdf(int $id, ProgramRepository $repository, InternshipTutorLinkRepository $tutorLinkRepository, InternshipBookletPdfExporter $exporter): Response
    {
        $program = $this->findProgramForStudentOrNotFound($id, $repository);
        $tutorLink = $tutorLinkRepository->findOneForStudentAndProgram($this->currentUser(), $program) ?? throw $this->createNotFoundException();

        try {
            $pdf = $exporter->export($tutorLink, $this->renderView(...));
        } catch (GotenbergUnavailableException) {
            $this->addFlash('error', 'internshipBookletPdfExportFailedFlashMessage');

            // Back to the reader on failure - that is where this button lives, and unlike the
            // bare document it used to sit next to, it has a flash-message region to show the
            // error in.
            return $this->redirectToRoute('app_program_internship_my_booklet', ['id' => $program->getId()]);
        }

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, sprintf('livret-alternant-%s.pdf', $tutorLink->getStudent()->getUsername())),
        ]);
    }

    private function findProgramForStudentOrNotFound(int $id, ProgramRepository $repository): Program
    {
        $program = $repository->find($id) ?? throw $this->createNotFoundException();

        if (!$program->getStudents()->contains($this->currentUser())) {
            throw $this->createNotFoundException();
        }

        $this->assertProgramFeatureEnabled($program->isInternshipManagementEnabled());

        return $program;
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
