<?php

declare(strict_types=1);

namespace App\Service\SchoolYearImport;

/**
 * A period moved by SchoolYearDateShift: `clamped` when a bound was brought back inside the year,
 * `outside` when the whole period fell out of it and now sits on the bound.
 */
final readonly class ShiftedRange
{
    public function __construct(
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
        public bool $clamped,
        public bool $outside,
    ) {
    }
}
