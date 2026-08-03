<?php

namespace App\Enum;

/**
 * Ce que l'étudiant reçoit en retour de son autoévaluation (design_handoff_carnet_de_notes,
 * PROMPT_MODIFICATIONS §9, écran 5a).
 *
 * Comparison ouvre l'écran comparé (5c) une fois l'estimation validée ET la notation de
 * l'enseignant devenue visible ; Alone garde la notation pour l'enseignant seul - l'étudiant voit
 * simplement que son autoévaluation est transmise. C'est un choix par travail, pas un réglage de
 * l'évaluation : la même évaluation peut être autoévaluée à l'aveugle en cours d'année et en
 * comparaison plus tard.
 */
enum SelfAssessmentFeedback: string
{
    case Comparison = 'comparison';
    case Alone = 'alone';

    public function labelKey(): string
    {
        return match ($this) {
            self::Comparison => 'selfAssessmentFeedbackComparisonLabel',
            self::Alone => 'selfAssessmentFeedbackAloneLabel',
        };
    }

    public function hintKey(): string
    {
        return match ($this) {
            self::Comparison => 'selfAssessmentFeedbackComparisonHint',
            self::Alone => 'selfAssessmentFeedbackAloneHint',
        };
    }
}
