<?php

declare(strict_types=1);

namespace App\Service\Jobboard;

use App\Enum\JobboardBtsAccess;
use App\Enum\JobboardContract;
use App\Enum\JobboardCountry;
use App\Enum\JobboardRemote;
use App\Service\QueryValue;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the filter column is asking for, read off the query string once.
 *
 * Every value goes through App\Service\QueryValue, and that is not a style preference:
 * `InputBag::getInt()` answers a **400** to the empty string, and a filter bar whose « Toutes »
 * option is `value=""` submits `?x=` as a matter of course. Four screens of this application died
 * that way on the same afternoon.
 *
 * Multi-valued filters travel comma-separated (`?contrat=cdi,stage`), which is what the Stimulus
 * controller builds and what a bookmarked URL stays readable as.
 *
 * The three administrator-only filters are read here like the others and **cleared by the finder**
 * for anybody else: a filter that does not exist in the interface must not become reachable by
 * typing it in the address bar.
 */
final readonly class OfferFilters
{
    /**
     * @param list<JobboardContract> $contracts
     * @param list<string>           $categories
     * @param list<JobboardRemote>   $remotes
     * @param list<JobboardCountry>  $countries
     * @param list<string>           $regions
     * @param list<int>               $trackIds
     * @param list<string>            $sources   slugs of App\Entity\JobboardSource, never enum cases:
     *                                           the list of sites is a table now, and a filter may not
     *                                           be the one place that still believes it is closed
     * @param list<JobboardBtsAccess> $btsAccess
     */
    public function __construct(
        public ?string $departement = null,
        public ?\DateTimeImmutable $firstSeenFrom = null,
        public array $contracts = [],
        public array $categories = [],
        public array $remotes = [],
        public array $countries = [],
        public array $regions = [],
        public array $trackIds = [],
        public array $sources = [],
        public array $btsAccess = [],
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $departement = QueryValue::trimmed($request, 'dept');
        $firstSeen = QueryValue::trimmed($request, 'vue');
        $firstSeenFrom = '' === $firstSeen ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', $firstSeen);

        return new self(
            departement: '' === $departement ? null : mb_substr($departement, 0, 3),
            firstSeenFrom: false === $firstSeenFrom ? null : $firstSeenFrom,
            contracts: self::enums($request, 'contrat', JobboardContract::class),
            categories: array_map(mb_strtolower(...), self::strings($request, 'categorie', 32)),
            remotes: self::enums($request, 'teletravail', JobboardRemote::class),
            countries: self::enums($request, 'pays', JobboardCountry::class),
            regions: self::strings($request, 'region', 120),
            trackIds: array_values(array_filter(array_map(intval(...), self::strings($request, 'filiere', 12)))),
            sources: array_map(mb_strtolower(...), self::strings($request, 'source', 32)),
            btsAccess: self::enums($request, 'acces', JobboardBtsAccess::class),
        );
    }

    /** Anything ticked at all? The « Réinitialiser » link only makes sense when something is. */
    public function isEmpty(): bool
    {
        return null === $this->departement
            && null === $this->firstSeenFrom
            && [] === $this->contracts
            && [] === $this->categories
            && [] === $this->remotes
            && [] === $this->countries
            && [] === $this->regions
            && [] === $this->trackIds
            && [] === $this->sources
            && [] === $this->btsAccess;
    }

    /** The administrator-only filters, dropped for everybody else. */
    public function withoutAdminFilters(): self
    {
        return new self(
            departement: $this->departement,
            firstSeenFrom: $this->firstSeenFrom,
            contracts: $this->contracts,
            categories: $this->categories,
            remotes: $this->remotes,
            countries: $this->countries,
            regions: $this->regions,
            trackIds: $this->trackIds,
        );
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return list<T>
     */
    private static function enums(Request $request, string $key, string $enum): array
    {
        $values = [];

        foreach (self::strings($request, $key, 32) as $raw) {
            $case = $enum::tryFrom($raw);

            if (null !== $case) {
                $values[] = $case;
            }
        }

        return $values;
    }

    /** @return list<string> */
    private static function strings(Request $request, string $key, int $maxLength): array
    {
        $raw = QueryValue::trimmed($request, $key);

        if ('' === $raw) {
            return [];
        }

        $values = [];
        // Ten is not a technical limit, it is the width of the menus: a query string carrying
        // fifty values is not a reader ticking boxes.
        foreach (\array_slice(explode(',', $raw), 0, 10) as $value) {
            $value = trim($value);

            if ('' !== $value) {
                $values[] = mb_substr($value, 0, $maxLength);
            }
        }

        return array_values(array_unique($values));
    }
}
