<?php

declare(strict_types=1);

namespace App\Service\Dossier;

use App\Entity\DossierSubmission;
use App\Entity\User;
use App\Enum\DossierDocumentStatus;

/**
 * One cible's whole line across the dossier — their cells, their avancement, their points
 * d'attention.
 *
 * The avancement is **over the documents obligatoires only** (handoff, « Avancement d'une cible »):
 * a facultative piece nobody handed in must not stop a dossier reading as complete, and one that was
 * handed in must not make it read as more complete than it is. A dossier with no obligatoire at all
 * is complete by construction - there is nothing anybody is required to do.
 */
final class DossierRow
{
    /**
     * @param array<int, DossierCell> $cells keyed by document id, in the dossier's own document order
     */
    public function __construct(
        public readonly User $student,
        public readonly string $className,
        public readonly array $cells,
        public readonly int $settledRequired,
        public readonly int $totalRequired,
    ) {
    }

    public function isComplete(): bool
    {
        return $this->settledRequired === $this->totalRequired;
    }

    public function percent(): int
    {
        return 0 === $this->totalRequired ? 100 : (int) round($this->settledRequired / $this->totalRequired * 100);
    }

    /** The most recent dépôt of this cible anywhere in the dossier, or null. */
    public function lastSubmission(): ?DossierSubmission
    {
        $last = null;

        foreach ($this->cells as $cell) {
            $candidate = $cell->latest;

            if (null !== $candidate && (null === $last || $candidate->getSubmittedAt() > $last->getSubmittedAt())) {
                $last = $candidate;
            }
        }

        return $last;
    }

    /**
     * The tags of the « Points d'attention » column, in the handoff's own order.
     *
     * One tag per *kind* of problem, never one per document: the column answers « faut-il regarder
     * cette cible », and three « En retard » tags answer it no better than one.
     *
     * @return list<DossierDocumentStatus>
     */
    public function alerts(): array
    {
        $alerts = [];

        foreach ([DossierDocumentStatus::Late, DossierDocumentStatus::ToCorrect, DossierDocumentStatus::AwaitingReview] as $status) {
            foreach ($this->cells as $cell) {
                if ($cell->status === $status) {
                    $alerts[] = $status;
                    break;
                }
            }
        }

        return $alerts;
    }

    public function countWithStatus(DossierDocumentStatus $status): int
    {
        $count = 0;

        foreach ($this->cells as $cell) {
            if ($cell->status === $status) {
                ++$count;
            }
        }

        return $count;
    }
}
