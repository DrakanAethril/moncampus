<?php

declare(strict_types=1);

namespace App\Service\TeacherDossier;

use App\Entity\TeacherDossierDocument;
use App\Entity\TeacherDossierReview;
use App\Entity\TeacherDossierSubmission;
use App\Entity\User;
use App\Enum\DossierReviewAction;
use App\Repository\TeacherDossierSubmissionRepository;
use App\Service\Dossier\DossierStatusResolver;
use App\Service\StagedUpload;
use App\Service\UploadIntake;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Every write on a piece: a dépôt, by the intervenant or for them, and the administration's answer
 * to it. One door for the two screens that deposit, so the refusal cannot differ between them.
 *
 * It persists and never flushes - the controller does, once, with whatever else the request wrote.
 * *Who* may call it is App\Security\Voter\TeacherDossierVoter's answer; *whether the piece takes one
 * more dépôt* is asked here, of the same rule the screen drew its button from.
 */
class TeacherDossierDeposits
{
    public function __construct(
        private readonly TeacherDossierSubmissionRepository $submissions,
        private readonly DossierStatusResolver $statuses,
        private readonly TeacherDossierFiles $files,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * May one more version be handed in on this piece today?
     *
     * A published dossier, and a piece not validated yet. The date limite refuses nothing.
     */
    public function accepts(TeacherDossierDocument $document, ?\DateTimeImmutable $today = null): bool
    {
        $dossier = $document->getDossier();

        return null !== $dossier
            && $dossier->isPublished()
            && $this->statuses->acceptsSubmission($document, $this->submissions->findForDocument($document), $today ?? new \DateTimeImmutable('today'));
    }

    /**
     * @throws TeacherDossierRefusal when the piece takes no more dépôt
     */
    public function depositFile(TeacherDossierDocument $document, User $by, StagedUpload $file): TeacherDossierSubmission
    {
        $this->assertAccepts($document);
        $dossier = $document->getDossier() ?? throw new TeacherDossierRefusal('teacherDossierSubmissionClosedFlashMessage');

        $submission = TeacherDossierSubmission::ofFile(
            $document,
            $by,
            $this->submissions->nextVersion($document),
            $this->files->store($dossier, $file),
            UploadIntake::originalName($file),
            UploadIntake::size($file),
        );
        $this->entityManager->persist($submission);

        return $submission;
    }

    /**
     * @throws TeacherDossierRefusal when the piece takes no more dépôt
     */
    public function depositUrl(TeacherDossierDocument $document, User $by, string $url): TeacherDossierSubmission
    {
        $this->assertAccepts($document);

        $submission = TeacherDossierSubmission::ofUrl($document, $by, $this->submissions->nextVersion($document), $url);
        $this->entityManager->persist($submission);

        return $submission;
    }

    /**
     * The administration's answer to the dépôt that counts.
     *
     * Only the **latest** version of a piece in validation is answered: an older one has already
     * been replaced, and a piece nobody has to read has nothing to answer.
     *
     * @throws TeacherDossierRefusal when there is nothing to answer, or a correction with no comment
     */
    public function review(TeacherDossierSubmission $submission, User $author, DossierReviewAction $action, ?string $comment): TeacherDossierReview
    {
        $document = $submission->getDocument();

        if (null === $document || !$document->needsValidation() || true !== $document->getDossier()?->isPublished()) {
            throw new TeacherDossierRefusal('teacherDossierReviewClosedFlashMessage');
        }

        if (DossierStatusResolver::latest($this->submissions->findForDocument($document)) !== $submission) {
            throw new TeacherDossierRefusal('teacherDossierReviewOutdatedFlashMessage');
        }

        try {
            $review = new TeacherDossierReview($submission, $author, $action, $comment);
        } catch (\InvalidArgumentException) {
            throw new TeacherDossierRefusal('teacherDossierReviewCommentRequiredFlashMessage');
        }

        $this->entityManager->persist($review);

        return $review;
    }

    private function assertAccepts(TeacherDossierDocument $document): void
    {
        if (!$this->accepts($document)) {
            throw new TeacherDossierRefusal('teacherDossierSubmissionClosedFlashMessage');
        }
    }
}
