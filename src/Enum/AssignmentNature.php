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
 * Listening and Watching, unlike every other one, are never picked: they can only be born of an
 * audio recording or of a video resource, from the "Enregistrements audio" and "Vidéos" tools,
 * which open the wizard with the nature already set. They are therefore absent from forLessonLog(),
 * the grid of natures on offer - a card for them would offer a nature with nothing to attach to it.
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
    case Watching = 'watching';

    /**
     * The types offered when creating an assignment from a séance, in the mockup's order.
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
            self::Watching => 'assignmentNatureWatchingLabel',
        };
    }

    // Sub-text of the type card in the 2b modal - what the type implies for the student.
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
            self::Watching => 'assignmentNatureWatchingHint',
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
            self::Watching => 'cm-badge--teal',
            self::ToPrepare, self::ToRead, self::Exercices, self::Autre => 'cm-badge--gray',
        };
    }

    public function expectsSubmission(): bool
    {
        return self::ToSubmit === $this;
    }

    /**
     * An assignment with neither submission nor attempt is settled by a declaration from the student:
     * « marquer comme fait » (mockup 4a). Submissions and quizzes have their own proof of completion.
     */
    public function expectsSelfDeclaration(): bool
    {
        return !\in_array($this, [self::ToSubmit, self::Quiz, self::SelfAssessment, self::Listening, self::Watching], true);
    }

    // The self-assessment has its own proof of completion - the submitted estimate - as submissions
    // and quizzes have theirs, hence its exclusion from expectsSelfDeclaration() above.
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

    /**
     * And so does Watching, for the same reason and through the same shape: the watch tracking says
     * what was really seen (App\Service\VideoWatchTracker), which is a stronger proof than a
     * declaration and does not have to be asked for.
     */
    public function expectsWatching(): bool
    {
        return self::Watching === $this;
    }
}
