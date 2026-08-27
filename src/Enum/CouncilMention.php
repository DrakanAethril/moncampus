<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a class council says about one student for a period - **one mention and one only**
 * (design/validated/gamification.md §5.4).
 *
 * This is the single place where the *quality* of a student's work enters the game, and it enters
 * through a human deliberation rather than through an average (§4, decision 5). If the machine paid
 * performance, the game would become a second academic ranking, worse than the first, and the
 * guarantee that points have nothing to do with marks would become indefensible.
 *
 * Two absences are deliberate. There is **no « progrès notables »**: the four positive mentions are
 * the ones the establishment pronounces. And an **avertissement is worth zero, never a negative**:
 * it is on the screen because the council pronounces it and the record has to carry it, and the
 * game simply does not read it - a warning is the establishment's business and is not a malus.
 */
enum CouncilMention: string
{
    case Excellence = 'excellence';
    case Congratulations = 'congratulations';
    case Compliments = 'compliments';
    case Encouragements = 'encouragements';
    case None = 'none';
    case Warning = 'warning';

    public function points(): int
    {
        return match ($this) {
            self::Excellence => 100,
            self::Congratulations => 80,
            self::Compliments => 55,
            self::Encouragements => 35,
            // Zero, and never negative: the game does not sanction, it only ever fails to reward.
            self::None, self::Warning => 0,
        };
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Excellence => 'councilMentionExcellenceLabel',
            self::Congratulations => 'councilMentionCongratulationsLabel',
            self::Compliments => 'councilMentionComplimentsLabel',
            self::Encouragements => 'councilMentionEncouragementsLabel',
            self::None => 'councilMentionNoneLabel',
            self::Warning => 'councilMentionWarningLabel',
        };
    }

    /** The keyboard letter of screen 6's one-pass entry - X, F, C, E, N, A. */
    public function shortcut(): string
    {
        return match ($this) {
            self::Excellence => 'X',
            self::Congratulations => 'F',
            self::Compliments => 'C',
            self::Encouragements => 'E',
            self::None => 'N',
            self::Warning => 'A',
        };
    }

    /**
     * The business order, best first - what a listing sorts on.
     *
     * Read this before writing `ORDER BY mention`: sorting on an enum column sorts the **stored
     * values**, so `compliments` would come before `excellence`. A listing ranks with a CASE in a
     * HIDDEN alias built from this, and never on the column - the trap this repository already paid
     * for once, on the quiz library's folders.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Excellence => 1,
            self::Congratulations => 2,
            self::Compliments => 3,
            self::Encouragements => 4,
            self::None => 5,
            self::Warning => 6,
        };
    }
}
