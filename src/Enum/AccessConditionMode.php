<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How the leaves of one condition combine. Deliberately two values and no nesting: the créa shows a
 * single toggle above the list ("Toutes les conditions" / "Au moins une"), and a teacher building an
 * arbitrary boolean tree in a form is a screen nobody asked for.
 */
enum AccessConditionMode: string
{
    case All = 'all';
    case Any = 'any';

    public function labelKey(): string
    {
        return match ($this) {
            self::All => 'accessConditionModeAllLabel',
            self::Any => 'accessConditionModeAnyLabel',
        };
    }
}
