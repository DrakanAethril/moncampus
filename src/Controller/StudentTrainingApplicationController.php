<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\TrainingApplication;
use App\Entity\TrainingOffer;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\TrainingApplicationElement;
use App\Enum\TrainingApplicationState;
use App\Repository\TrainingApplicationRepository;
use App\Repository\TrainingOfferRepository;
use App\Service\FileUploadService;
use App\Service\PdfUploadValidator;
use App\Service\SchoolMailLockChecker;
use App\Service\StudentMailboxResolver;
use App\Service\StudentSignatureBuilder;
use App\Service\TrainingApplicationWorkflow;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The student's side of the practice workflow
 * (design_handoff_workflow_postulation, screens 8a, 8b and 8e).
 *
 * One page to see where the four validations stand and which offers can be applied to (8a), one to
 * write the application (8b), one to read what came back and resend (8e).
 *
 * Nothing here ever sends a mail: the application is written like one and stored like one, but it
 * only ever travels as far as a teacher's screen. That is the whole point - the mailbox stays shut
 * until somebody has read what this student would have sent to a real company.
 */
#[IsGranted('ROLE_STUDENT')]
#[RequiresFeature(Feature::TrainingOffers)]
class StudentTrainingApplicationController extends AbstractController
{
    public function __construct(
        private readonly TrainingOfferRepository $offerRepository,
        private readonly TrainingApplicationRepository $applicationRepository,
        private readonly TrainingApplicationWorkflow $workflow,
        private readonly SchoolMailLockChecker $lockChecker,
        private readonly StudentSignatureBuilder $signatureBuilder,
        private readonly StudentMailboxResolver $mailboxResolver,
        private readonly FileUploadService $fileUploadService,
        private readonly PdfUploadValidator $pdfValidator,
    ) {
    }

    #[Route(path: '/school-mail/validation', name: 'app_training_validation', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $student */
        $student = $this->getUser();
        $applications = $this->applicationRepository->findForStudent($student);
        $current = $this->currentApplication($applications);

        return $this->render('training_application/validation.html.twig', [
            'offers' => $this->offerRepository->findVisibleForStudent($student),
            'applicationsByOffer' => $this->indexByOffer($applications),
            'current' => $current,
            'elements' => TrainingApplicationElement::all(),
            'unlocked' => $this->lockChecker->isUnlocked($student),
        ]);
    }

    #[Route(path: '/school-mail/validation/offers/{id}/apply', name: 'app_training_apply', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function apply(Request $request, TrainingOffer $offer): Response
    {
        /** @var User $student */
        $student = $this->getUser();
        $this->denyUnlessVisible($offer, $student);

        // One application per offer: a second one would split the four validations across two
        // reviews and neither would ever reach four.
        $existing = $this->applicationRepository->findOneForStudentAndOffer($student, $offer);

        if (null !== $existing) {
            return $this->redirectToRoute('app_training_application', ['id' => $existing->getId()]);
        }

        $values = [
            // Nothing is pre-filled: writing the subject is part of the exercise being reviewed.
            'subject' => (string) $request->request->get('subject', ''),
            'body' => (string) $request->request->get('body', ''),
        ];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('training_apply', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            // As many files as the student chose to join, or none at all: nothing about the
            // attachments blocks the send (design_handoff_postulation_redaction, "Aucun blocage").
            // Each of them is still read for what it is - a PDF, under 10 Mo - since that is the
            // platform's own rule for the documents a validator will have to open.
            $files = $this->uploadedFiles($request);
            $error = match (true) {
                '' === trim($values['subject']) => 'trainingApplicationSubjectRequiredError',
                '' === trim($values['body']) => 'trainingApplicationBodyRequiredError',
                default => $this->firstFileError($files),
            };

            if (null === $error) {
                $application = $this->workflow->submit($student, $offer, $values['subject'], $values['body'], $files);
                $this->addFlash('success', 'trainingApplicationSubmittedFlash');

                return $this->redirectToRoute('app_training_application', ['id' => $application->getId()]);
            }

            return $this->renderApply($offer, $student, $values, $error, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->renderApply($offer, $student, $values);
    }

    #[Route(path: '/school-mail/validation/applications/{id}', name: 'app_training_application', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(TrainingApplication $application): Response
    {
        $this->denyUnlessOwned($application);

        return $this->render('training_application/show.html.twig', [
            'application' => $application,
            'elements' => TrainingApplicationElement::all(),
        ]);
    }

    #[Route(path: '/school-mail/validation/applications/{id}/resend', name: 'app_training_application_resubmit', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function resubmit(Request $request, TrainingApplication $application): Response
    {
        $this->denyUnlessOwned($application);

        if (!$this->isCsrfTokenValid('training_resubmit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if (TrainingApplicationState::CorrectionsRequested !== $application->getState()) {
            // Resending something nobody asked to see again would jump the queue in front of
            // students who are actually waiting on a review.
            throw $this->createNotFoundException();
        }

        $files = $this->uploadedFiles($request);
        $error = $this->firstFileError($files);

        if (null !== $error) {
            // A resend carries the same files as a first send, and deserves the same refusal.
            $this->addFlash('danger', $error);

            return $this->redirectToRoute('app_training_application', ['id' => $application->getId()]);
        }

        $this->workflow->resubmit(
            $application,
            $files,
            (string) $request->request->get('body', ''),
        );

        $this->addFlash('success', 'trainingApplicationResubmittedFlash');

        return $this->redirectToRoute('app_training_application', ['id' => $application->getId()]);
    }

    /** The offer PDF, the only thing a student reads before applying. */
    #[Route(path: '/school-mail/validation/offers/{id}/document', name: 'app_training_offer_document', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function document(TrainingOffer $offer): Response
    {
        /** @var User $student */
        $student = $this->getUser();
        $this->denyUnlessVisible($offer, $student);

        if (null === $offer->getDocumentKey()) {
            throw $this->createNotFoundException();
        }

        return $this->redirect($this->fileUploadService->url($offer->getDocumentKey()));
    }

    /**
     * The files joined to the form, whatever their number - a single field, posted as a list, is
     * the only way in: "un seul point d'entrée d'ajout".
     *
     * @return list<UploadedFile>
     */
    private function uploadedFiles(Request $request): array
    {
        return array_values(array_filter(
            $request->files->all()['attachments'] ?? [],
            static fn ($file): bool => $file instanceof UploadedFile,
        ));
    }

    /**
     * @param list<UploadedFile> $files
     *
     * @return ?string the first refusal, so the student is told about one problem at a time
     */
    private function firstFileError(array $files): ?string
    {
        foreach ($files as $file) {
            $error = $this->pdfValidator->validate($file);

            if (null !== $error) {
                return $error;
            }
        }

        return null;
    }

    /**
     * @param array{subject: string, body: string} $values
     */
    private function renderApply(TrainingOffer $offer, User $student, array $values, ?string $error = null, int $status = Response::HTTP_OK): Response
    {
        $mailbox = $this->mailboxResolver->addressFor($student);

        return $this->render('training_application/apply.html.twig', [
            'offer' => $offer,
            'values' => $values,
            'error' => $error,
            'signature' => $this->signatureBuilder->build($student, $mailbox),
        ], new Response(status: $status));
    }

    /**
     * @param list<TrainingApplication> $applications
     *
     * @return array<int, TrainingApplication>
     */
    private function indexByOffer(array $applications): array
    {
        $indexed = [];

        foreach ($applications as $application) {
            $indexed[$application->getOffer()?->getId()] = $application;
        }

        return $indexed;
    }

    /**
     * The application the checklist speaks about: the validated one if there is one, otherwise the
     * most recent. A student only ever has one that matters at a time.
     *
     * @param list<TrainingApplication> $applications
     */
    private function currentApplication(array $applications): ?TrainingApplication
    {
        foreach ($applications as $application) {
            if (TrainingApplicationState::Validated === $application->getState()) {
                return $application;
            }
        }

        return $applications[0] ?? null;
    }

    private function denyUnlessVisible(TrainingOffer $offer, User $student): void
    {
        foreach ($this->offerRepository->findVisibleForStudent($student) as $visible) {
            if ($visible->getId() === $offer->getId()) {
                return;
            }
        }

        throw $this->createAccessDeniedException();
    }

    private function denyUnlessOwned(TrainingApplication $application): void
    {
        /** @var User $student */
        $student = $this->getUser();

        if ($application->getStudent()?->getId() !== $student->getId()) {
            throw $this->createAccessDeniedException();
        }
    }
}
