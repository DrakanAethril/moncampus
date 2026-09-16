<?php

declare(strict_types=1);

namespace App\Service\Dossier;

use App\Entity\Dossier;
use App\Entity\DossierDocument;
use App\Entity\User;
use App\Enum\DossierDocumentStatus;

/**
 * The whole state of a published dossier, read once and handed to every screen that shows it.
 *
 * It exists so that the grid, the two other Suivi tabs, the gestion screen and the cible's own page
 * are four readings of **one** computation. Building it costs two queries whatever the size of the
 * matrix (the cibles, and every dépôt of the dossier with its échanges) - see
 * App\Repository\DossierSubmissionRepository::findForDossier().
 */
final class DossierBoard
{
    /**
     * @param list<DossierDocument> $documents in the dossier's own order
     * @param list<DossierRow>      $rows      one per cible, ordered by name
     */
    public function __construct(
        public readonly Dossier $dossier,
        public readonly array $documents,
        public readonly array $rows,
    ) {
    }

    public function cibleCount(): int
    {
        return \count($this->rows);
    }

    /** The cibles whose every document obligatoire is settled - the « Dossiers complets » KPI. */
    public function completeCount(): int
    {
        return \count(array_filter($this->rows, static fn (DossierRow $row): bool => $row->isComplete()));
    }

    /** How many cells across the whole dossier hold one status - the three other KPIs. */
    public function countWithStatus(DossierDocumentStatus $status): int
    {
        $count = 0;

        foreach ($this->rows as $row) {
            $count += $row->countWithStatus($status);
        }

        return $count;
    }

    /**
     * The avancement of one document across the cibles - « 7/10 » on the gestion screen.
     *
     * Counted on `isSettled()` rather than on « a dépôt exists », so a document in validation only
     * counts once somebody has validated it. Facultative documents get the bar too: here the
     * denominator is the cibles, not the obligatoires.
     *
     * @return array{done: int, total: int, percent: int}
     */
    public function documentProgress(DossierDocument $document): array
    {
        $id = $document->getId();
        $done = 0;

        foreach ($this->rows as $row) {
            if (isset($row->cells[$id]) && $row->cells[$id]->isSettled()) {
                ++$done;
            }
        }

        $total = \count($this->rows);

        return [
            'done' => $done,
            'total' => $total,
            'percent' => 0 === $total ? 0 : (int) round($done / $total * 100),
        ];
    }

    /** The cells of one document, one per cible, in the rows' own order. @return list<DossierCell> */
    public function cellsOfDocument(DossierDocument $document): array
    {
        $id = $document->getId();
        $cells = [];

        foreach ($this->rows as $row) {
            if (isset($row->cells[$id])) {
                $cells[] = $row->cells[$id];
            }
        }

        return $cells;
    }

    /**
     * The dépôts waiting for a validateur, oldest first — the « File d'attente » of the validation
     * screen.
     *
     * It is the dossier's queue, not one validateur's: nothing routes a dépôt to a person, any
     * validateur may answer it, and answering it takes it out of everybody's list at once.
     *
     * @return list<DossierCell>
     */
    public function validationQueue(): array
    {
        $queue = [];

        foreach ($this->rows as $row) {
            foreach ($row->cells as $cell) {
                if ($cell->status->isAwaitingValidator()) {
                    $queue[] = $cell;
                }
            }
        }

        usort($queue, static fn (DossierCell $a, DossierCell $b): int => $a->latest?->getSubmittedAt() <=> $b->latest?->getSubmittedAt());

        return $queue;
    }

    public function rowOf(User $student): ?DossierRow
    {
        foreach ($this->rows as $row) {
            if ($row->student->getId() === $student->getId()) {
                return $row;
            }
        }

        return null;
    }
}
