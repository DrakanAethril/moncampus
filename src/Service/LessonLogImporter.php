<?php

namespace App\Service;

use App\Entity\Assignment;
use App\Entity\LessonSession;
use App\Repository\AssignmentCompletionRepository;
use App\Repository\AssignmentRepository;
use App\Repository\AssignmentSubmissionRepository;
use App\Repository\LessonSessionRepository;

/**
 * Ce qu'il faut savoir pour proposer « Importer depuis une séance »
 * (design_handoff_cahier_de_texte 2a) : quelles séances peuvent servir de source, et quels travaux
 * d'une séance sont déjà commencés par des étudiants.
 *
 * La reprise elle-même ne passe pas par ici : l'import ne fait que déposer les trois textes dans les
 * éditeurs, sans rien enregistrer, et c'est le contrôleur qui les rend en JSON à l'écran.
 */
class LessonLogImporter
{
    public function __construct(
        private readonly LessonSessionRepository $lessonSessionRepository,
        private readonly AssignmentRepository $assignmentRepository,
        private readonly AssignmentSubmissionRepository $submissionRepository,
        private readonly AssignmentCompletionRepository $completionRepository,
    ) {
    }

    /**
     * Les séances proposées en tête de menu, dans l'ordre de priorité de la maquette : le même
     * cours à un autre groupe cette année, puis l'année précédente.
     *
     * « Le même cours » se reconnaît au nom de la matière : deux groupes ont chacun leur propre
     * Topic, portant le même intitulé, et c'est le seul lien entre eux que le modèle offre. Seules
     * les séances dont le cahier de texte dit quelque chose sont proposées - reprendre un cahier
     * vide ne rendrait aucun service.
     *
     * @return list<array{session: LessonSession, kind: string}>
     */
    public function suggestionsFor(LessonSession $session): array
    {
        $sameYear = [];
        $previousYears = [];

        foreach ($this->lessonSessionRepository->findComparableFilledSessions($session) as $candidate) {
            $isSameYear = $candidate->getProgram()?->getSchoolYear()?->getId() === $session->getProgram()?->getSchoolYear()?->getId();
            $isSameYear ? $sameYear[] = $candidate : $previousYears[] = $candidate;
        }

        $suggestions = [];
        if ([] !== $sameYear) {
            $suggestions[] = ['session' => $sameYear[0], 'kind' => 'otherGroup'];
        }
        if ([] !== $previousYears) {
            $suggestions[] = ['session' => $previousYears[0], 'kind' => 'previousYear'];
        }

        return $suggestions;
    }

    /** @return list<LessonSession> */
    public function browsableFor(LessonSession $session): array
    {
        return $this->lessonSessionRepository->findComparableFilledSessions($session);
    }

    /**
     * Les identifiants des travaux d'une séance déjà commencés par un étudiant - ce que l'écran doit
     * savoir pour prévenir plus fermement avant de les supprimer.
     *
     * @return list<int>
     */
    public function worksWithProduction(LessonSession $session): array
    {
        $ids = [];
        foreach ($this->assignmentRepository->findForLessonSession($session) as $work) {
            if ($this->hasStudentProduction($work)) {
                $ids[] = (int) $work->getId();
            }
        }

        return $ids;
    }

    /**
     * Un travail sur lequel un étudiant a déposé un fichier ou déclaré avoir fini : le supprimer
     * emporterait ces traces, ce qui doit rester un geste délibéré de l'enseignant.
     */
    private function hasStudentProduction(Assignment $work): bool
    {
        return $this->submissionRepository->hasAnyForAssignment($work)
            || $this->completionRepository->hasAnyForAssignment($work);
    }
}
