<?php

declare(strict_types=1);

namespace App\Service\TeacherDossier;

use App\Entity\TeacherDossierDocument;
use App\Entity\TeacherDossierReview;
use App\Entity\TeacherDossierSubmission;
use App\Enum\DossierDocumentStatus;

/**
 * One piece of a dossier intervenant as it stands today: its status, its dépôts, and whether one
 * more may be handed in.
 *
 * It carries every version rather than only the last, because the intervenant's « mes dépôts » and
 * the administration's history of a correction are the same list read from two sides.
 */
final class TeacherDossierLine
{
    /**
     * @param list<TeacherDossierSubmission> $submissions every version, oldest first
     */
    public function __construct(
        public readonly TeacherDossierDocument $document,
        public readonly DossierDocumentStatus $status,
        public readonly ?TeacherDossierSubmission $latest,
        public readonly array $submissions,
        public readonly bool $acceptsSubmission,
    ) {
    }

    /** The review that produced the current status, or null when nobody has answered the dépôt. */
    public function lastReview(): ?TeacherDossierReview
    {
        return $this->latest?->lastReview();
    }

    public function isSettled(): bool
    {
        return $this->status->isSettled();
    }

    /**
     * Is the next gesture the intervenant's?
     *
     * Nothing handed in, late, or a correction asked for. A piece waiting for the administration is
     * not: its holder has done their part, and telling them otherwise is how a banner stops being
     * read.
     */
    public function awaitsTeacher(): bool
    {
        return \in_array($this->status, [DossierDocumentStatus::Missing, DossierDocumentStatus::Late, DossierDocumentStatus::ToCorrect], true);
    }

    /**
     * The versions before the one that counts, newest first - « remplacé » is a reading of « a
     * higher version exists », never a stored state.
     *
     * @return list<TeacherDossierSubmission>
     */
    public function previousVersions(): array
    {
        return \array_slice(array_reverse($this->submissions), 1);
    }
}
