<?php

declare(strict_types=1);

namespace App\Service\Sirene;

/**
 * One page of a register search. `total` is the API's own count, of companies, before the closed
 * establishments are taken out - which is why a page shows fewer than 25 and says how many it
 * dropped (`dropped`) rather than pretending otherwise.
 */
final readonly class RegistryPage
{
    /** The register caps any search at this many results (page × per_page ≤ 10 000). */
    public const int MAX_RESULTS = 10000;

    public const int PER_PAGE = 25;

    /**
     * @param list<RegistryCompany> $companies
     */
    public function __construct(
        public int $total,
        public int $page,
        public int $totalPages,
        public array $companies,
        public int $dropped,
        public \DateTimeImmutable $readAt,
    ) {
    }

    /** The last page one may ask for: the API refuses past 10 000 results. */
    public function lastReachablePage(): int
    {
        return min($this->totalPages, intdiv(self::MAX_RESULTS, self::PER_PAGE));
    }

    public function isCapped(): bool
    {
        return $this->total >= self::MAX_RESULTS;
    }
}
