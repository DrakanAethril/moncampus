<?php

namespace App\Controller;

use App\Entity\TrainingApplication;
use App\Entity\User;
use App\Enum\TrainingApplicationElement;
use App\Repository\ProgramRepository;
use App\Security\StructureAccessChecker;
use App\Service\FileUploadService;
use App\Service\TrainingApplicationWorkflow;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A validator reading one application and passing judgement on its four elements
 * (design_handoff_workflow_postulation, screen 8d).
 *
 * Any validator designated on the offer may review - the handoff says so explicitly, and a queue
 * that only one person can drain is a queue that stops moving in July. Who decided what, and on
 * which version, is recorded on every verdict.
 */
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class TrainingApplicationReviewController extends AbstractController
{
    public function __construct(
        private readonly TrainingApplicationWorkflow $workflow,
        private readonly FileUploadService $fileUploadService,
        private readonly ProgramRepository $programRepository,
        private readonly StructureAccessChecker $accessChecker,
    ) {
    }

    #[Route(path: '/applications/{id}/review', name: 'app_training_application_review', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function review(Request $request, TrainingApplication $application): Response
    {
        /** @var User $validator */
        $validator = $this->getUser();
        $this->denyUnlessValidator($application, $validator);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('training_review', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $decisions = [];
            $error = null;

            foreach (TrainingApplicationElement::all() as $element) {
                $decision = (string) $request->request->get('decision_'.$element->value, '');
                $remark = trim((string) $request->request->get('remark_'.$element->value, ''));

                if ('' === $decision) {
                    continue;
                }

                // A correction asked for without a reason cannot be acted on - the student would be
                // told to fix something without being told what.
                if ('refused' === $decision && '' === $remark) {
                    $error = 'trainingReviewRemarkRequiredError';

                    break;
                }

                $decisions[$element->value] = ['decision' => $decision, 'remark' => $remark];
            }

            if (null === $error && [] === $decisions) {
                $error = 'trainingReviewNoDecisionError';
            }

            if (null !== $error) {
                return $this->renderReview($application, $error, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $this->workflow->review($application, $validator, $decisions);
            $this->addFlash('success', 'trainingReviewSavedFlash');

            return $this->redirectToRoute('app_training_application_review', ['id' => $application->getId()]);
        }

        return $this->renderReview($application);
    }

    /**
     * A file joined to the application, readable by the validators who have to judge it.
     *
     * Addressed by the attachment's own id rather than by a document type: since
     * design_handoff_postulation_redaction, a student joins as many untyped files as they like, and
     * there is no longer a "the CV" of an application to point at.
     */
    #[Route(path: '/applications/{id}/documents/{attachmentId}', name: 'app_training_application_file', requirements: ['id' => '\d+', 'attachmentId' => '\d+'], methods: ['GET'])]
    public function attachment(TrainingApplication $application, int $attachmentId): Response
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->denyUnlessValidator($application, $viewer);

        foreach ($application->getCurrentVersion()?->getAttachments() ?? [] as $attachment) {
            if ($attachment->getId() === $attachmentId) {
                return $this->redirect($this->fileUploadService->url($attachment->getStorageKey()));
            }
        }

        throw $this->createNotFoundException();
    }

    private function renderReview(TrainingApplication $application, ?string $error = null, int $status = Response::HTTP_OK): Response
    {
        return $this->render('training_application/review.html.twig', [
            'application' => $application,
            'elements' => TrainingApplicationElement::all(),
            'error' => $error,
            // The class this application was opened from, so the breadcrumb climbs back to the
            // queue rather than dead-ending on the home page.
            'program' => $this->visibleProgramFor($application),
        ], new Response(status: $status));
    }

    private function visibleProgramFor(TrainingApplication $application): ?\App\Entity\Program
    {
        $student = $application->getStudent();

        if (null === $student) {
            return null;
        }

        foreach ($this->programRepository->findAllActiveForStudent($student) as $program) {
            if ($this->accessChecker->isProgramVisible($program)) {
                return $program;
            }
        }

        return null;
    }

    private function denyUnlessValidator(TrainingApplication $application, User $user): void
    {
        if (true !== $application->getOffer()?->hasValidator($user)) {
            throw $this->createAccessDeniedException();
        }
    }
}
