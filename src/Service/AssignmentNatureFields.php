<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Assignment;
use App\Enum\AssignmentNature;
use App\Enum\SelfAssessmentFeedback;

/**
 * Puts the fields of a travail à faire back in line with the nature it ended up having.
 *
 * Each nature only carries part of step 3's fields, but the ones it does not carry stay in the DOM
 * and come back with the form. Left alone, a quiz picked and then abandoned would follow a travail
 * that became a reading - and the student would open a reading expecting a quiz attempt nobody can
 * make.
 *
 * Extracted out of App\Controller\AssignmentController. Every rule here is pure entity work, which
 * is why it is worth testing rather than clicking: none of it is visible from the screen, which
 * shows the fields of the nature currently selected and says nothing about what it just discarded.
 */
final class AssignmentNatureFields
{
    public function apply(Assignment $assignment): void
    {
        $nature = $assignment->getNature();

        if (AssignmentNature::Quiz !== $nature) {
            $assignment->setQuizInstance(null);
            // The target only ever qualifies a quiz: it must not survive a change of nature, or a
            // reading would silently become impossible to complete.
            $assignment->setMinimumScorePercent(null);
        }

        if (AssignmentNature::Listening !== $nature) {
            $assignment->setAudioRecording(null);
        }

        if (AssignmentNature::Watching !== $nature) {
            $assignment->setVideoResource(null);
        }

        if (AssignmentNature::SelfAssessment !== $nature) {
            $assignment->setEvaluation(null);
            $assignment->setSelfAssessmentFeedback(null);
        } else {
            // La maquette annonce un seul retour possible - « note comparée à la sienne » - et ne
            // pose donc pas la question.
            $assignment->setSelfAssessmentFeedback($assignment->getSelfAssessmentFeedback() ?? SelfAssessmentFeedback::Comparison);
        }

        if (!$assignment->expectsSubmission()) {
            $assignment->setLateSubmissionAllowed(false);

            foreach ($assignment->getExpectedProductions()->toArray() as $production) {
                $assignment->removeExpectedProduction($production);
            }
        }

        if (AssignmentNature::ToRead !== $nature) {
            $assignment->setReadTrackingEnabled(false);
        }

        // Une production sans nom n'annonce rien : la ligne restée vide est simplement abandonnée.
        // Les positions se referment derrière elle, sinon le tableau de bord de l'étudiant
        // ordonnerait autour d'un trou.
        $position = 0;
        foreach ($assignment->getExpectedProductions()->toArray() as $production) {
            if ('' === trim($production->getName())) {
                $assignment->removeExpectedProduction($production);

                continue;
            }

            $production->setPosition($position++);
        }

        if (!$assignment->isGraded()) {
            $assignment->setGradingVisibleToStudents(false);
        }
    }
}
