<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Assignment;
use App\Enum\AssignmentNature;

/**
 * What a travail à faire must carry before it can be saved.
 *
 * Three of the natures point at something else in the app and are meaningless without it: a quiz
 * assignment without its QuizInstance, a self-assessment without the Evaluation it is an estimate
 * of, or a survey without the SurveyCampaign it asks to answer, would all be a row a student can
 * open and not do. A class is required whatever the nature.
 *
 * The wizard already blocks all three in the browser - this is the server-side net for a request
 * that did not come from the screen, which is exactly why it is worth a test rather than a click.
 *
 * Extracted out of App\Controller\AssignmentController, which decided what was missing and reported
 * it as form errors in the same method. Only the decision lives here; turning it into errors on the
 * right field stays the controller's job.
 */
final class AssignmentNatureRequirements
{
    /**
     * @return array<string, string> field name => translation key, empty when nothing is missing.
     *                               Every missing field at once, since the wizard shows each error
     *                               on the step it belongs to.
     */
    public function missing(Assignment $assignment): array
    {
        $missing = [];

        if (AssignmentNature::Quiz === $assignment->getNature() && null === $assignment->getQuizInstance()) {
            $missing['quizInstance'] = 'assignmentWizardQuizRequiredMessage';
        }

        if (AssignmentNature::SelfAssessment === $assignment->getNature() && null === $assignment->getEvaluation()) {
            $missing['evaluation'] = 'assignmentWizardEvaluationRequiredMessage';
        }

        if (AssignmentNature::Survey === $assignment->getNature() && null === $assignment->getSurveyCampaign()) {
            $missing['surveyCampaign'] = 'assignmentWizardSurveyRequiredMessage';
        }

        if (null === $assignment->getProgram()) {
            $missing['program'] = 'assignmentWizardClassRequiredMessage';
        }

        return $missing;
    }
}
