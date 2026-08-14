<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Which half of the loan history the "Prêts" list is showing.
 *
 * The two are shown separately rather than together: a closed loan and a running one are not read
 * for the same reason - one is looked up, the other is acted on - and they do not even sort by the
 * same date. There is deliberately no "all" case; the screen never mixes them.
 */
enum LaptopLoanScope: string
{
    case Active = 'active';
    case Returned = 'returned';

    /**
     * The date each half is ordered by, most recent first: a running loan by when it went out, a
     * closed one by when it came back - which is the event that just happened and the one the
     * operator is looking for.
     */
    public function orderField(): string
    {
        return match ($this) {
            self::Active => 'loan.lentAt',
            self::Returned => 'loan.returnedAt',
        };
    }
}
