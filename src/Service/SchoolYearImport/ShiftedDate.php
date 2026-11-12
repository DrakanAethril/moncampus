<?php

declare(strict_types=1);

namespace App\Service\SchoolYearImport;

/** A date moved by SchoolYearDateShift, and whether it had to be brought back to a bound of the year. */
final readonly class ShiftedDate
{
    public function __construct(
        public \DateTimeImmutable $date,
        public bool $clamped,
    ) {
    }
}
