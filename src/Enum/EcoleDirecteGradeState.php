<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What sending one student's grade to École Directe would do. Only New and Replace are sent.
 */
enum EcoleDirecteGradeState: string implements HasBadge
{
    case New = 'new';
    case Replace = 'replace';
    case Same = 'same';

    /** MonCampus holds a grade row with nothing in it - no number, no status to write. */
    case Empty = 'empty';

    /** No École Directe student carries exactly this name in the class - or two do. */
    case NoMatch = 'no_match';

    public function labelKey(): string
    {
        return match ($this) {
            self::New => 'ecoleDirecteSendNewLabel',
            self::Replace => 'ecoleDirecteSendReplaceLabel',
            self::Same => 'ecoleDirecteSendSameLabel',
            self::Empty => 'ecoleDirecteGradeEmptyLabel',
            self::NoMatch => 'ecoleDirecteGradeNoMatchLabel',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::New => BadgeTone::Green,
            self::Replace => BadgeTone::Gold,
            self::Same => BadgeTone::Gray,
            self::Empty => BadgeTone::Gray,
            self::NoMatch => BadgeTone::Red,
        };
    }

    public function sends(): bool
    {
        return self::New === $this || self::Replace === $this;
    }
}
