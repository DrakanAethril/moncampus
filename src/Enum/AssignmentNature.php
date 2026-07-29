<?php

namespace App\Enum;

/**
 * What kind of work an Assignment asks of the student - only ToSubmit opens the file submission
 * box (the original Assignment behavior, and the migration default for pre-existing rows); the
 * other natures are announce-only items surfaced on the student dashboard's "Travail à réaliser"
 * card (design_handoff_dashboards etu-a).
 */
enum AssignmentNature: string
{
    case ToSubmit = 'to_submit';
    case ToRevise = 'to_revise';
    case ToPrepare = 'to_prepare';
    case ToRead = 'to_read';

    public function labelKey(): string
    {
        return match ($this) {
            self::ToSubmit => 'assignmentNatureToSubmitLabel',
            self::ToRevise => 'assignmentNatureToReviseLabel',
            self::ToPrepare => 'assignmentNatureToPrepareLabel',
            self::ToRead => 'assignmentNatureToReadLabel',
        };
    }

    // Dashboard grammar (PROMPT_CLAUDE_CODE_DASHBOARDS §2): À rendre = blue, À réviser = amber,
    // À préparer / À lire = gray.
    public function badgeClass(): string
    {
        return match ($this) {
            self::ToSubmit => 'cm-badge--blue',
            self::ToRevise => 'cm-badge--gold',
            self::ToPrepare, self::ToRead => 'cm-badge--gray',
        };
    }

    public function expectsSubmission(): bool
    {
        return self::ToSubmit === $this;
    }
}
