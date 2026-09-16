<?php

declare(strict_types=1);

namespace App\Service\Dossier;

use App\Entity\Dossier;
use App\Entity\DossierDocument;
use App\Entity\DossierSubmission;
use App\Entity\User;
use App\Repository\DossierSubmissionRepository;

/**
 * Builds the App\Service\Dossier\DossierBoard of a dossier in a fixed number of queries.
 *
 * The shape that matters is the grouping below: every dépôt of the dossier is read once and filed
 * under (document, cible), so resolving six hundred cells reads nothing more. A resolver called per
 * cell would be correct and unusable - the Suivi grid is cibles × documents by construction.
 */
class DossierBoardBuilder
{
    public function __construct(
        private readonly DossierTargetResolver $targets,
        private readonly DossierSubmissionRepository $submissions,
        private readonly DossierStatusResolver $statuses,
    ) {
    }

    public function build(Dossier $dossier, ?\DateTimeImmutable $today = null): DossierBoard
    {
        $today ??= new \DateTimeImmutable('today');

        /** @var list<DossierDocument> $documents */
        $documents = array_values($dossier->getDocuments()->toArray());
        $byCell = $this->groupByCell($this->submissions->findForDossier($dossier));

        $rows = [];

        foreach ($this->targets->resolve($dossier) as $cible) {
            $rows[] = $this->row($cible['student'], $cible['className'], $documents, $byCell, $today);
        }

        return new DossierBoard($dossier, $documents, $rows);
    }

    /**
     * The board of a single cible - what the student's own screen reads.
     *
     * Same computation, one row: the student never sees the others' dépôts, and never queries them.
     */
    public function buildForStudent(Dossier $dossier, User $student, string $className, ?\DateTimeImmutable $today = null): DossierRow
    {
        $today ??= new \DateTimeImmutable('today');

        /** @var list<DossierDocument> $documents */
        $documents = array_values($dossier->getDocuments()->toArray());
        $byCell = $this->groupByCell($this->submissions->findForDossierAndStudent($dossier, $student));

        return $this->row($student, $className, $documents, $byCell, $today);
    }

    /**
     * @param list<DossierDocument>                  $documents
     * @param array<string, list<DossierSubmission>> $byCell
     */
    private function row(User $student, string $className, array $documents, array $byCell, \DateTimeImmutable $today): DossierRow
    {
        $cells = [];
        $settled = 0;
        $required = 0;

        foreach ($documents as $document) {
            $id = $document->getId();

            if (null === $id) {
                continue;
            }

            $submissions = $byCell[$id.':'.$student->getId()] ?? [];
            $cell = new DossierCell(
                $document,
                $student,
                $className,
                $this->statuses->resolve($document, $submissions, $today),
                DossierStatusResolver::latest($submissions),
                $submissions,
            );

            $cells[$id] = $cell;

            if ($document->isRequired()) {
                ++$required;
                $settled += $cell->isSettled() ? 1 : 0;
            }
        }

        return new DossierRow($student, $className, $cells, $settled, $required);
    }

    /**
     * @param list<DossierSubmission> $submissions
     *
     * @return array<string, list<DossierSubmission>>
     */
    private function groupByCell(array $submissions): array
    {
        $grouped = [];

        foreach ($submissions as $submission) {
            $grouped[$submission->getDocument()?->getId().':'.$submission->getStudent()?->getId()][] = $submission;
        }

        return $grouped;
    }
}
