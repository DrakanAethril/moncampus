<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a student sees of an object whose condition is not met yet. A field chosen by the teacher,
 * never a deduction: two conditions carrying exactly the same data ask for opposite screens -
 * "quiz >= 60 %" to open the next chapter must be announced, "quiz >= 90 %" for an extension
 * workshop must not. What separates them is a pedagogical intention, and no computation guesses it.
 *
 * The default is Locked, the safe side: an unjustified greyed row is seen and reported, while an
 * unjustified hiding makes a work vanish with no diagnosis.
 */
enum AccessConditionDisplay: string
{
    case Locked = 'locked';
    case Hidden = 'hidden';

    public function labelKey(): string
    {
        return match ($this) {
            self::Locked => 'accessConditionDisplayLockedLabel',
            self::Hidden => 'accessConditionDisplayHiddenLabel',
        };
    }

    /** The consequence, which is what the teacher actually needs to read next to the choice. */
    public function descriptionKey(): string
    {
        return match ($this) {
            self::Locked => 'accessConditionDisplayLockedDescription',
            self::Hidden => 'accessConditionDisplayHiddenDescription',
        };
    }
}
