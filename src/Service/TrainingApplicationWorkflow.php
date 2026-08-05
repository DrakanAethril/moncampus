<?php

namespace App\Service;

use App\Entity\TrainingApplication;
use App\Entity\TrainingApplicationReview;
use App\Entity\TrainingApplicationVersion;
use App\Entity\TrainingOffer;
use App\Entity\User;
use App\Enum\TrainingApplicationDecision;
use App\Enum\TrainingApplicationElement;
use App\Enum\TrainingApplicationState;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The life of a practice application (design_handoff_workflow_postulation, screens 8b, 8d, 8e).
 *
 * Three moves, and the rules of the handoff live in them rather than in the screens that call them:
 *
 * - **submit** creates version 1 and puts the application in front of the validators;
 * - **review** records one verdict per element, and only for elements not already acquired - a
 *   validation obtained on v1 is never asked for again;
 * - **resubmit** opens a new version with the corrected files, and hands it back to the validators.
 *
 * Unlocking is not a step here: the mailbox opens because a fourth element got validated, which
 * App\Service\SchoolMailLockChecker reads directly. Nothing sets a flag that could drift.
 */
class TrainingApplicationWorkflow
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FileUploadService $fileUploadService,
        private readonly StudentSignatureBuilder $signatureBuilder,
        private readonly StudentMailboxResolver $mailboxResolver,
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Screen 8b: the application leaves the student's hands for the first time. */
    public function submit(
        User $student,
        TrainingOffer $offer,
        string $subject,
        string $body,
        UploadedFile $cv,
        UploadedFile $coverLetter,
    ): TrainingApplication {
        $application = (new TrainingApplication())
            ->setStudent($student)
            ->setOffer($offer)
            ->setState(TrainingApplicationState::Received);

        $version = (new TrainingApplicationVersion())
            ->setNumber(1)
            ->setSubject($subject)
            ->setBody($body)
            // The signature as it reads today: the student may edit theirs afterwards, and what was
            // validated has to stay readable as it was validated.
            ->setSignatureSnapshot($this->signatureText($student));

        $this->attachFiles($student, $version, $cv, $coverLetter);
        $application->addVersion($version);

        $this->entityManager->persist($application);
        $this->entityManager->flush();

        return $application;
    }

    /** Screen 8e: the student replaces what was refused and hands the application back. */
    public function resubmit(TrainingApplication $application, ?UploadedFile $cv, ?UploadedFile $coverLetter, ?string $body = null): void
    {
        $previous = $application->getCurrentVersion();

        $version = (new TrainingApplicationVersion())
            ->setNumber(($previous?->getNumber() ?? 0) + 1)
            ->setSubject($previous?->getSubject())
            ->setBody(null !== $body && '' !== trim($body) ? $body : (string) $previous?->getBody())
            ->setSignatureSnapshot($this->signatureText($application->getStudent()))
            // Files not replaced carry over: only what was refused goes back for review, so the
            // rest has to stay exactly what was already validated.
            ->setCvKey($previous?->getCvKey())
            ->setCvName($previous?->getCvName())
            ->setCoverLetterKey($previous?->getCoverLetterKey())
            ->setCoverLetterName($previous?->getCoverLetterName());

        $this->attachFiles($application->getStudent(), $version, $cv, $coverLetter);

        $application->addVersion($version);
        $application->setState(TrainingApplicationState::Resent);

        $this->entityManager->flush();
    }

    /**
     * Screen 8d: one validator, one pass, up to four verdicts.
     *
     * @param array<string, array{decision: string, remark: ?string}> $decisions keyed by element value
     */
    public function review(TrainingApplication $application, User $validator, array $decisions): void
    {
        $versionNumber = $application->getVersionNumber();

        foreach (TrainingApplicationElement::all() as $element) {
            // An acquired validation is never revisited - not even by the validator who granted it.
            if ($application->isValidated($element)) {
                continue;
            }

            $submitted = $decisions[$element->value] ?? null;

            if (null === $submitted || '' === ($submitted['decision'] ?? '')) {
                continue;
            }

            $decision = TrainingApplicationDecision::tryFrom($submitted['decision']);

            if (null === $decision || TrainingApplicationDecision::Pending === $decision) {
                continue;
            }

            $remark = trim((string) ($submitted['remark'] ?? ''));

            $application->addReview(
                (new TrainingApplicationReview())
                    ->setElement($element)
                    ->setDecision($decision)
                    ->setRemark('' === $remark ? null : $remark)
                    ->setValidator($validator)
                    ->setVersionNumber($versionNumber)
            );
        }

        $application->setState($application->isComplete()
            ? TrainingApplicationState::Validated
            : TrainingApplicationState::CorrectionsRequested);

        $this->entityManager->flush();

        if (TrainingApplicationState::Validated === $application->getState()) {
            $this->notifyUnlocked($application);
        }
    }

    /**
     * The one notification the handoff asks for: the fourth validation opened the mailbox, and the
     * student has no reason to keep checking.
     *
     * A failure to send is logged, not raised: the unlocking already happened, and it holds whether
     * or not the mail went out.
     */
    private function notifyUnlocked(TrainingApplication $application): void
    {
        $student = $application->getStudent();
        $recipient = $student?->getContactEmail();

        if (null === $student || null === $recipient) {
            return;
        }

        try {
            $this->mailer->send(
                (new TemplatedEmail())
                    ->to($recipient)
                    ->subject($this->translator->trans('trainingApplicationUnlockedEmailSubject'))
                    ->htmlTemplate('emails/training_application_unlocked.html.twig')
                    ->context([
                        'firstName' => $student->getFirstname(),
                        'offerTitle' => $application->getOffer()?->getTitle(),
                        'mailbox' => $this->mailboxResolver->addressFor($student),
                    ])
            );
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Training application: could not notify a student their mailbox unlocked.', [
                'student' => $student->getUsername(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function attachFiles(?User $student, TrainingApplicationVersion $version, ?UploadedFile $cv, ?UploadedFile $coverLetter): void
    {
        $prefix = sprintf('training-applications/%s/', $student?->getUsername() ?? 'unknown');

        if (null !== $cv) {
            $version
                ->setCvKey($this->fileUploadService->upload($prefix, $cv->getClientOriginalName(), $cv))
                ->setCvName($cv->getClientOriginalName());
        }

        if (null !== $coverLetter) {
            $version
                ->setCoverLetterKey($this->fileUploadService->upload($prefix, $coverLetter->getClientOriginalName(), $coverLetter))
                ->setCoverLetterName($coverLetter->getClientOriginalName());
        }
    }

    /** The signature flattened to text, which is what a snapshot has to be to stay readable. */
    private function signatureText(?User $student): ?string
    {
        if (null === $student) {
            return null;
        }

        $signature = $this->signatureBuilder->build($student, $this->mailboxResolver->addressFor($student));

        return $this->signatureBuilder->toText($signature);
    }
}
