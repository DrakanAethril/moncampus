<?php

declare(strict_types=1);

namespace App\Service\TeacherDossier;

use App\Entity\TeacherDossier;
use App\Entity\TeacherDossierSubmission;
use App\Repository\TeacherDossierSubmissionRepository;
use App\Service\Dossier\DossierStatusResolver;

/**
 * Builds the App\Service\TeacherDossier\TeacherDossierSheet of one dossier, or of a whole list of
 * them, in **one** query for the dépôts whatever the number of dossiers.
 *
 * The status of a piece is App\Service\Dossier\DossierStatusResolver's answer - the same rule as
 * the dossiers documentaires, read through the same three interfaces - and nothing here adds to it
 * but the one thing a dossier intervenant has of its own: only a **published** dossier accepts a
 * dépôt, a draft being nobody's yet and a closed one being closed.
 */
class TeacherDossierSheets
{
    public function __construct(
        private readonly TeacherDossierSubmissionRepository $submissions,
        private readonly DossierStatusResolver $statuses,
    ) {
    }

    public function of(TeacherDossier $dossier, ?\DateTimeImmutable $today = null): TeacherDossierSheet
    {
        return $this->ofAll([$dossier], $today)[0];
    }

    /**
     * @param list<TeacherDossier> $dossiers
     *
     * @return list<TeacherDossierSheet> in the order given
     */
    public function ofAll(array $dossiers, ?\DateTimeImmutable $today = null): array
    {
        $today ??= new \DateTimeImmutable('today');
        $byDocument = [];

        foreach ($this->submissions->findForDossiers($dossiers) as $submission) {
            $byDocument[(int) $submission->getDocument()?->getId()][] = $submission;
        }

        return array_map(fn (TeacherDossier $dossier): TeacherDossierSheet => $this->sheet($dossier, $byDocument, $today), $dossiers);
    }

    /**
     * @param array<int, list<TeacherDossierSubmission>> $byDocument
     */
    private function sheet(TeacherDossier $dossier, array $byDocument, \DateTimeImmutable $today): TeacherDossierSheet
    {
        $lines = [];
        $settled = 0;
        $required = 0;

        foreach ($dossier->getDocuments() as $document) {
            $id = $document->getId();

            if (null === $id) {
                continue;
            }

            $submissions = $byDocument[$id] ?? [];
            $line = new TeacherDossierLine(
                $document,
                $this->statuses->resolve($document, $submissions, $today),
                DossierStatusResolver::latest($submissions),
                $submissions,
                $dossier->isPublished() && $this->statuses->acceptsSubmission($document, $submissions, $today),
            );
            $lines[$id] = $line;

            if ($document->isRequired()) {
                ++$required;
                $settled += $line->isSettled() ? 1 : 0;
            }
        }

        return new TeacherDossierSheet($dossier, $lines, $settled, $required);
    }
}
