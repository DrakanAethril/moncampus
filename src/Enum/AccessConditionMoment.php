<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Which end of a séance's slot a seance_passed leaf watches. "End" is the default - the course has
 * taken place, which is what "après la séance" means everywhere else in the application; "start"
 * exists for the support that opens as the class begins, to be worked through in the room.
 *
 * Two values on purpose: both are useful, and a third would be a date in disguise.
 */
enum AccessConditionMoment: string
{
    case Start = 'start';
    case End = 'end';

    public function labelKey(): string
    {
        return match ($this) {
            self::Start => 'accessConditionMomentStartLabel',
            self::End => 'accessConditionMomentEndLabel',
        };
    }
}
