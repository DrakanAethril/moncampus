<?php

declare(strict_types=1);

namespace App\Service\AlternanceImport;

/**
 * The two dates of one apprenticeship contract, as read from the spreadsheet.
 *
 * A pair rather than two loose arguments so the parser can answer "unreadable" with a single null,
 * and so nothing downstream can pass an end date where a start one is expected.
 */
final readonly class ContractPeriod
{
    public function __construct(
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
    ) {
    }

    public function isChronological(): bool
    {
        return $this->end > $this->start;
    }
}
