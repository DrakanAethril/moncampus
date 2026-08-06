<?php

namespace App\Enum;

/**
 * Where one assignment stands for one student on their "Travail à faire" screen
 * (design_handoff_travail_a_faire, screen 3c). Never persisted - it is read off the submissions,
 * completions, quiz attempts and dismissals every time the screen is drawn.
 *
 * The mockup only ever names a state through a tag and a color, never in a sentence, so these
 * cases are what the screen is built from rather than something it writes out.
 */
enum StudentWorkState: string
{
    /** Still to do, deadline ahead. */
    case Todo = 'todo';

    /** Deadline passed, nothing handed in, still doable. */
    case Late = 'late';

    /** Handed in (or declared done) before the deadline - stays listed, in green, still editable. */
    case Submitted = 'submitted';

    /** Set aside by the student, deadline ahead - stays listed, greyed out, with "Rétablir". */
    case Dismissed = 'dismissed';

    /** Finished and past its deadline - leaves the list for "Derniers travaux". */
    case Done = 'done';

    /** Submission window closed with nothing handed in - "Non rendu", in the side column. */
    case Missed = 'missed';

    /** The two groups of the list, in the mockup's order. Everything else sits in the side column. */
    public function isListed(): bool
    {
        return \in_array($this, [self::Late, self::Todo, self::Submitted, self::Dismissed], true);
    }

    public function isFinished(): bool
    {
        return \in_array($this, [self::Submitted, self::Done], true);
    }
}
