<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\TrainingApplication;
use App\Entity\TrainingApplicationAttachment;
use App\Entity\TrainingApplicationReview;
use App\Entity\TrainingApplicationVersion;
use App\Entity\TrainingOffer;
use App\Entity\User;
use App\Enum\TrainingApplicationDecision;
use App\Enum\TrainingApplicationElement;
use App\Enum\TrainingApplicationState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

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
 * App\Service\SchoolMailLockChecker reads directly. Nothing sets a flag that could drift, and
 * nothing has to be sent either - the student sees it on screen 8a the next time they look.
 */
class TrainingApplicationWorkflow
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FileUploadService $fileUploadService,
        private readonly StudentSignatureBuilder $signatureBuilder,
        private readonly StudentMailboxResolver $mailboxResolver,
    ) {
    }

    /**
     * Screen 8b: the application leaves the student's hands for the first time.
     *
     * @param list<UploadedFile> $files as many as the student chose to join, possibly none - the
     *                                  handoff forbids making any of them a condition to send
     */
    public function submit(
        User $student,
        TrainingOffer $offer,
        string $subject,
        string $body,
        array $files,
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

        $this->attachFiles($student, $version, $files);
        $application->addVersion($version);

        $this->entityManager->persist($application);
        $this->entityManager->flush();

        return $application;
    }

    /**
     * Screen 8e: the student replaces what was refused and hands the application back.
     *
     * @param list<UploadedFile> $files              the files joined on top of the ones kept, possibly none
     * @param ?list<int>         $keptAttachmentIds the previous version's attachments the student
     *                                              left in place; null means the screen did not say,
     *                                              and they all carry over
     */
    public function resubmit(TrainingApplication $application, array $files, ?string $body = null, ?string $subject = null, ?array $keptAttachmentIds = null): void
    {
        $previous = $application->getCurrentVersion();

        // An empty field is never a correction: it is a screen that did not carry the value, and
        // the previous version's own text is what stands.
        $version = (new TrainingApplicationVersion())
            ->setNumber(($previous?->getNumber() ?? 0) + 1)
            ->setSubject(null !== $subject && '' !== trim($subject) ? $subject : $previous?->getSubject())
            ->setBody(null !== $body && '' !== trim($body) ? $body : (string) $previous?->getBody())
            ->setSignatureSnapshot($this->signatureText($application->getStudent()));

        // The kept files first, then the new ones: joining a document adds to what was already read
        // rather than sweeping it away, and only the × on a chip removes anything. A carried-over
        // file becomes a row of its own pointing at the same object - the previous version keeps
        // its list intact, so what a validator read stays exactly what they read.
        foreach ($previous?->getAttachments() ?? [] as $attachment) {
            if (null !== $keptAttachmentIds && !\in_array($attachment->getId(), $keptAttachmentIds, true)) {
                continue;
            }

            $version->addAttachment(new TrainingApplicationAttachment($attachment->getStorageKey(), $attachment->getName()));
        }

        $this->attachFiles($application->getStudent(), $version, $files);

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

            if (null === $submitted || '' === $submitted['decision']) {
                continue;
            }

            $decision = TrainingApplicationDecision::tryFrom($submitted['decision']);

            if (null === $decision || TrainingApplicationDecision::Pending === $decision) {
                continue;
            }

            $remark = trim((string) ($submitted['remark'] ?? ''));
            $remark = '' === $remark ? null : $remark;
            $standing = $application->getReviewFor($element);

            // A verdict reposted word for word is not a new verdict. The screen hands back the
            // standing correction pre-filled, so a validator who opens the application to re-read
            // it and saves would otherwise redate their own feedback without changing a word of it
            // - and the banner naming who wrote it, and when, would move for nothing.
            if (null !== $standing
                && $standing->getVersionNumber() === $versionNumber
                && $standing->getDecision() === $decision
                && $standing->getRemark() === $remark) {
                continue;
            }

            $application->addReview(
                (new TrainingApplicationReview())
                    ->setElement($element)
                    ->setDecision($decision)
                    ->setRemark($remark)
                    ->setValidator($validator)
                    ->setVersionNumber($versionNumber)
            );
        }

        $application->setState($application->isComplete()
            ? TrainingApplicationState::Validated
            : TrainingApplicationState::CorrectionsRequested);

        $this->entityManager->flush();
    }

    /** @param list<UploadedFile> $files */
    private function attachFiles(?User $student, TrainingApplicationVersion $version, array $files): void
    {
        $prefix = sprintf('training-applications/%s/', $student?->getUsername() ?? 'unknown');

        foreach ($files as $file) {
            $name = $file->getClientOriginalName();
            $version->addAttachment(new TrainingApplicationAttachment($this->fileUploadService->upload($prefix, $name, $file), $name));
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
