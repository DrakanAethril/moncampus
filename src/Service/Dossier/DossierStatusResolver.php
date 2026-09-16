<?php

declare(strict_types=1);

namespace App\Service\Dossier;

use App\Entity\DossierDocument;
use App\Entity\DossierSubmission;
use App\Enum\DossierDocumentStatus;

/**
 * The single answer to « where does this cible stand on this document ».
 *
 * Every screen of the feature goes through here - the grid, the cible's own page, the validateur's
 * queue, the avancement bars - which is the point: the status is derived from the dates and the
 * dépôts, so it cannot be stored, cannot be stale, and cannot differ between two screens reading
 * the same row.
 *
 * The seven cases read in the order a reader would ask them:
 *
 *  1. the document is not visible yet, so for this cible it does not exist → `Upcoming`;
 *  2. nothing has been handed in → `Missing`, or `Late` once the date limite has passed;
 *  3. something has been handed in on a document nobody reads → `Deposited`, and that is the end;
 *  4. something has been handed in on a document a validateur reads → the **last échange** decides:
 *     none yet → `AwaitingReview`, a validation → `Validated`, a correction request → `ToCorrect`.
 *
 * Note 4 reads the last review of the *latest* dépôt only. A correction requested on v1 is answered
 * by v2, and v2 carries no review, so the cell goes back to `AwaitingReview` - which is exactly what
 * the validateur's queue is: the dépôts nobody has answered yet.
 */
class DossierStatusResolver
{
    /**
     * @param list<DossierSubmission> $submissions every dépôt of this cible on this document,
     *                                             any order
     */
    public function resolve(DossierDocument $document, array $submissions, \DateTimeImmutable $today): DossierDocumentStatus
    {
        if (!$document->isVisibleOn($today)) {
            return DossierDocumentStatus::Upcoming;
        }

        $latest = self::latest($submissions);

        if (null === $latest) {
            return $document->isOverdueOn($today) ? DossierDocumentStatus::Late : DossierDocumentStatus::Missing;
        }

        if (!$document->needsValidation()) {
            return DossierDocumentStatus::Deposited;
        }

        $review = $latest->lastReview();

        if (null === $review) {
            return DossierDocumentStatus::AwaitingReview;
        }

        return $review->isValidation() ? DossierDocumentStatus::Validated : DossierDocumentStatus::ToCorrect;
    }

    /**
     * May this cible deposit on this document right now?
     *
     * Three refusals and one derogation, and the derogation is the one that matters: a document
     * whose date limite has passed is closed unless it allows dépassement - but a **correction
     * requested reopens it**, because asking for a correction the cible cannot then hand in is
     * asking for nothing. A validated document is closed for the opposite reason: there is nothing
     * left to ask.
     *
     * @param list<DossierSubmission> $submissions
     */
    public function acceptsSubmission(DossierDocument $document, array $submissions, \DateTimeImmutable $today): bool
    {
        $status = $this->resolve($document, $submissions, $today);

        if (DossierDocumentStatus::Upcoming === $status || DossierDocumentStatus::Validated === $status) {
            return false;
        }

        if (!$document->isOverdueOn($today)) {
            return true;
        }

        return $document->isLateAllowed() || DossierDocumentStatus::ToCorrect === $status;
    }

    /**
     * The dépôt that counts - the highest version.
     *
     * By version rather than by date: the version is what the unique index guarantees, and two
     * dépôts saved in the same second would otherwise be ordered by nothing.
     *
     * @param list<DossierSubmission> $submissions
     */
    public static function latest(array $submissions): ?DossierSubmission
    {
        $latest = null;

        foreach ($submissions as $submission) {
            if (null === $latest || $submission->getVersion() > $latest->getVersion()) {
                $latest = $submission;
            }
        }

        return $latest;
    }
}
