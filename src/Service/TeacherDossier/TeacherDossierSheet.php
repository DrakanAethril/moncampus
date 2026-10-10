<?php

declare(strict_types=1);

namespace App\Service\TeacherDossier;

use App\Entity\TeacherDossier;
use App\Entity\TeacherDossierGroup;
use App\Entity\TeacherDossierSubmission;
use App\Enum\DossierDocumentStatus;

/**
 * The whole state of one dossier intervenant, read once and handed to every screen that shows it:
 * the row of the administration's list, the fiche, the intervenant's own page and the home banner
 * are four readings of this one computation.
 *
 * The avancement is **over the pieces obligatoires only**: a facultative piece nobody handed in
 * must not stop a dossier reading as complete. A dossier with no obligatoire is complete by
 * construction.
 */
final class TeacherDossierSheet
{
    /**
     * @param array<int, TeacherDossierLine> $lines keyed by document id, in the dossier's own order
     */
    public function __construct(
        public readonly TeacherDossier $dossier,
        public readonly array $lines,
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

    /**
     * The tags of the « Points d'attention » column - one per *kind* of problem, never one per
     * piece: the column answers « faut-il ouvrir ce dossier », and three « En retard » answer it no
     * better than one.
     *
     * @return list<DossierDocumentStatus>
     */
    public function alerts(): array
    {
        $alerts = [];

        foreach ([DossierDocumentStatus::Late, DossierDocumentStatus::ToCorrect, DossierDocumentStatus::AwaitingReview] as $status) {
            if ($this->countWithStatus($status) > 0) {
                $alerts[] = $status;
            }
        }

        return $alerts;
    }

    public function countWithStatus(DossierDocumentStatus $status): int
    {
        return \count(array_filter($this->lines, static fn (TeacherDossierLine $line): bool => $line->status === $status));
    }

    /**
     * The pieces **obligatoires** whose next gesture is the intervenant's - what the home banner
     * counts. Zero on anything but a published dossier: a draft is not theirs yet, and a closed one
     * asks for nothing.
     *
     * @return array{toSubmit: int, toCorrect: int}
     */
    public function awaitedFromTeacher(): array
    {
        $toSubmit = 0;
        $toCorrect = 0;

        if ($this->dossier->isPublished()) {
            foreach ($this->lines as $line) {
                if (!$line->document->isRequired() || !$line->awaitsTeacher()) {
                    continue;
                }

                if (DossierDocumentStatus::ToCorrect === $line->status) {
                    ++$toCorrect;
                } else {
                    ++$toSubmit;
                }
            }
        }

        return ['toSubmit' => $toSubmit, 'toCorrect' => $toCorrect];
    }

    public function awaitsTeacher(): bool
    {
        $awaited = $this->awaitedFromTeacher();

        return $awaited['toSubmit'] + $awaited['toCorrect'] > 0;
    }

    /** The most recent dépôt anywhere in the dossier, or null. */
    public function lastSubmission(): ?TeacherDossierSubmission
    {
        $last = null;

        foreach ($this->lines as $line) {
            $candidate = $line->latest;

            if (null !== $candidate && (null === $last || $candidate->getSubmittedAt() > $last->getSubmittedAt())) {
                $last = $candidate;
            }
        }

        return $last;
    }

    /**
     * The lines filed under their group, the ungrouped ones last - a group with no piece is left
     * out here: on a reading screen an empty heading says nothing.
     *
     * @return list<array{group: TeacherDossierGroup|null, lines: list<TeacherDossierLine>}>
     */
    public function groupedLines(): array
    {
        $groups = [];

        foreach ($this->dossier->groupedDocuments() as $entry) {
            $lines = [];

            foreach ($entry['documents'] as $document) {
                $id = $document->getId();

                if (null !== $id && isset($this->lines[$id])) {
                    $lines[] = $this->lines[$id];
                }
            }

            if ([] !== $lines) {
                $groups[] = ['group' => $entry['group'], 'lines' => $lines];
            }
        }

        return $groups;
    }
}
