<?php

declare(strict_types=1);

namespace App\Service\Dossier;

use App\Entity\DossierDocument;
use App\Entity\DossierReview;
use App\Entity\DossierSubmission;
use App\Entity\User;
use App\Enum\DossierDocumentStatus;

/**
 * One crossing of cible × document — the cell of the Suivi grid, and the row of every other screen
 * of the feature.
 *
 * It carries every dépôt of that crossing rather than only the last one, because « Mes dépôts » on
 * the cible's screen and the historique on the validateur's are the same list read from two sides.
 */
final class DossierCell
{
    /**
     * @param list<DossierSubmission> $submissions every version, oldest first
     */
    public function __construct(
        public readonly DossierDocument $document,
        public readonly User $student,
        // The formation the cible reads as - carried here rather than looked up, because the
        // validateur's screen shows a cell on its own, outside the row it came from.
        public readonly string $className,
        public readonly DossierDocumentStatus $status,
        public readonly ?DossierSubmission $latest,
        public readonly array $submissions,
    ) {
    }

    /** The échange that produced the current status, or null when nobody has answered the dépôt. */
    public function lastReview(): ?DossierReview
    {
        return $this->latest?->lastReview();
    }

    /** Is the cible's side of this document done? Counted over the obligatoires only. */
    public function isSettled(): bool
    {
        return $this->status->isSettled();
    }

    /**
     * The versions in the order the screens draw them - newest first, the older ones being the
     * « Remplacé » rows underneath.
     *
     * @return list<DossierSubmission>
     */
    public function versionsNewestFirst(): array
    {
        $versions = $this->submissions;
        usort($versions, static fn (DossierSubmission $a, DossierSubmission $b): int => $b->getVersion() <=> $a->getVersion());

        return $versions;
    }
}
