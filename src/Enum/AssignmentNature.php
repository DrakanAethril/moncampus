<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What kind of work an Assignment asks of the student - only ToSubmit opens the file submission
 * box (the original Assignment behavior, and the migration default for pre-existing rows); the
 * other natures are announce-only items surfaced on the student dashboard's "Travail à réaliser"
 * card (design_handoff_dashboards etu-a).
 *
 * Exercices/Quiz/Autre viennent du cahier de texte (design_handoff_cahier_de_texte 2b). ToPrepare
 * lui est antérieur et reste en place, avec ses devoirs : il ne figure pas dans la grille de types
 * du 2b, qui reprend celle de la maquette, mais l'écran devoir historique continue de l'offrir.
 *
 * Listening, unlike every other one, is never picked: a listening assignment can only be born of an
 * audio recording, from the "Enregistrements audio" tool which opens the wizard with that nature
 * already set. It is therefore absent from forLessonLog(), the grid of natures on offer.
 */
enum AssignmentNature: string
{
    case ToSubmit = 'to_submit';
    case ToRevise = 'to_revise';
    case ToPrepare = 'to_prepare';
    case ToRead = 'to_read';
    case Exercices = 'exercices';
    case Quiz = 'quiz';
    case Autre = 'autre';
    case SelfAssessment = 'self_assessment';
    case Listening = 'listening';

    /**
     * Les types proposés à la création d'un travail depuis une séance, dans l'ordre de la maquette.
     *
     * @return list<self>
     */
    public static function forLessonLog(): array
    {
        return [self::ToSubmit, self::ToRead, self::Exercices, self::ToRevise, self::Quiz, self::SelfAssessment, self::Autre];
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::ToSubmit => 'assignmentNatureToSubmitLabel',
            self::ToRevise => 'assignmentNatureToReviseLabel',
            self::ToPrepare => 'assignmentNatureToPrepareLabel',
            self::ToRead => 'assignmentNatureToReadLabel',
            self::Exercices => 'assignmentNatureExercicesLabel',
            self::Quiz => 'assignmentNatureQuizLabel',
            self::Autre => 'assignmentNatureAutreLabel',
            self::SelfAssessment => 'assignmentNatureSelfAssessmentLabel',
            self::Listening => 'assignmentNatureListeningLabel',
        };
    }

    // Sous-texte de la carte de type dans le modal 2b - ce que le type implique pour l'étudiant.
    public function hintKey(): string
    {
        return match ($this) {
            self::ToSubmit => 'assignmentNatureToSubmitHint',
            self::ToRevise => 'assignmentNatureToReviseHint',
            self::ToPrepare => 'assignmentNatureToPrepareHint',
            self::ToRead => 'assignmentNatureToReadHint',
            self::Exercices => 'assignmentNatureExercicesHint',
            self::Quiz => 'assignmentNatureQuizHint',
            self::Autre => 'assignmentNatureAutreHint',
            self::SelfAssessment => 'assignmentNatureSelfAssessmentHint',
            self::Listening => 'assignmentNatureListeningHint',
        };
    }

    // Dashboard grammar (PROMPT_CLAUDE_CODE_DASHBOARDS §2): À rendre = blue, À réviser = amber,
    // À préparer / À lire = gray.
    public function badgeClass(): string
    {
        return match ($this) {
            self::ToSubmit => 'cm-badge--blue',
            self::ToRevise => 'cm-badge--gold',
            self::Quiz => 'cm-badge--purple',
            self::SelfAssessment => 'cm-badge--blue',
            self::Listening => 'cm-badge--teal',
            self::ToPrepare, self::ToRead, self::Exercices, self::Autre => 'cm-badge--gray',
        };
    }

    public function expectsSubmission(): bool
    {
        return self::ToSubmit === $this;
    }

    /**
     * Un travail sans dépôt ni passation se solde par une déclaration de l'étudiant : « marquer
     * comme fait » (maquette 4a). Le dépôt et le quiz ont leur propre preuve d'achèvement.
     */
    public function expectsSelfDeclaration(): bool
    {
        return !\in_array($this, [self::ToSubmit, self::Quiz, self::SelfAssessment, self::Listening], true);
    }

    // L'autoévaluation a sa propre preuve d'achèvement - l'estimation validée - comme le dépôt et
    // le quiz ont la leur, d'où son exclusion de expectsSelfDeclaration() ci-dessus.
    public function expectsSelfAssessment(): bool
    {
        return self::SelfAssessment === $this;
    }

    /**
     * Listening carries its own proof of completion too - the listen tracking, which says exactly
     * what the student heard - hence its exclusion from expectsSelfDeclaration() above: there is no
     * point declaring yourself done when the platform already knows.
     */
    public function expectsListening(): bool
    {
        return self::Listening === $this;
    }
}
