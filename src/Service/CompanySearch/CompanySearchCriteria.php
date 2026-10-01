<?php

declare(strict_types=1);

namespace App\Service\CompanySearch;

use App\Entity\CompanySearchCategory;
use App\Enum\CompanyOrganisationType;
use App\Enum\EmployeeBand;
use App\Service\Sirene\RegistryCompany;

/**
 * Everything « Trouver une entreprise » was asked, and **the one place it becomes an API request**
 * (design/validated/vivier-entreprises.md §8.1). Immutable: the controller builds it from the query
 * string, the screen redraws its filters from it, the service sends toApiRequest() and caches on it.
 *
 * Three things are always applied and never shown: `etat_administratif=A` (a closed company takes
 * no trainee), the open-establishment filter (in the client) and, unless a box says otherwise,
 * `est_entrepreneur_individuel=false` (D6).
 *
 * A search needs a « quoi » - without one the department alone hits the register's cap of 10 000 -
 * and a « où », without which the API matches no establishment at all.
 */
final readonly class CompanySearchCriteria
{
    public const string WHERE_DEPARTMENTS = 'departments';
    public const string WHERE_COMMUNE = 'commune';

    /** The radii offered; the API refuses anything over 50 km. */
    public const array RADII = [5, 10, 15, 30, 50];

    /**
     * @param list<CompanySearchCategory> $categories
     * @param list<string>                $nafCodes   the advanced filter, already checked against the nomenclature
     * @param list<string>                $departments `87`, `2A`, `971`
     * @param list<EmployeeBand>          $bands
     */
    public function __construct(
        public array $categories = [],
        public array $nafCodes = [],
        public string $query = '',
        public string $where = self::WHERE_DEPARTMENTS,
        public array $departments = [],
        public ?string $communeLabel = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public int $radius = 15,
        public array $bands = [],
        public bool $includeUnknownSize = true,
        public CompanyOrganisationType $organisationType = CompanyOrganisationType::Any,
        public bool $includeIndividuals = false,
        public int $page = 1,
    ) {
    }

    /** Something to search on: a category, a code or a word. */
    public function hasWhat(): bool
    {
        return [] !== $this->categories || [] !== $this->nafCodes || '' !== $this->query;
    }

    public function hasWhere(): bool
    {
        return self::WHERE_COMMUNE === $this->where
            ? null !== $this->latitude && null !== $this->longitude
            : [] !== $this->departments;
    }

    /**
     * Why this cannot be sent as it stands, as a translation key - null when it can.
     */
    public function problem(): ?string
    {
        if (!$this->hasWhat()) {
            return 'companySearchNoWhatError';
        }

        if (!$this->hasWhere()) {
            return self::WHERE_COMMUNE === $this->where ? 'companySearchNoCommuneError' : 'companySearchNoDepartmentError';
        }

        $solo = array_values(array_filter($this->categories, static fn (CompanySearchCategory $category): bool => $category->isSolo()));

        // The API's geographic search filters on activity codes and nothing else - it refuses a
        // word outright, and ignores a flag or a size.
        if (self::WHERE_COMMUNE === $this->where && ('' !== $this->query || [] !== $solo)) {
            return 'companySearchCommuneCodesOnlyError';
        }
        if (\count($solo) > 1 || ([] !== $solo && \count($this->categories) + \count($this->nafCodes) > 1)) {
            return 'companySearchSoloCategoryError';
        }

        return null;
    }

    /**
     * The point distances are measured from: the commune searched around, else null.
     *
     * @return array{float, float}|null
     */
    public function origin(): ?array
    {
        return self::WHERE_COMMUNE === $this->where && null !== $this->latitude && null !== $this->longitude
            ? [$this->latitude, $this->longitude]
            : null;
    }

    /**
     * @return array{endpoint: '/search'|'/near_point', parameters: array<string, string>}
     */
    public function toApiRequest(): array
    {
        $parameters = ['etat_administratif' => 'A'];

        $codes = $this->nafCodes;
        $sizeBrackets = null;
        foreach ($this->categories as $category) {
            $codes = [...$codes, ...$category->getNafCodes()];
            if (null !== $category->getFlag()) {
                $parameters[$category->getFlag()->apiParameter()] = 'true';
            }
            if (null !== $category->getMinimumBand()) {
                $sizeBrackets = $this->bracketsFrom($category->getMinimumBand());
            }
        }
        $codes = array_values(array_unique($codes));
        sort($codes);
        if ([] !== $codes) {
            $parameters['activite_principale'] = implode(',', $codes);
        }

        if ('' !== $this->query) {
            $parameters['q'] = $this->query;
        }

        // A category that is a size (« Grandes entreprises ») decides the size; otherwise the
        // boxes do, and no box at all means every size.
        if (null === $sizeBrackets && [] !== $this->bands) {
            $sizeBrackets = array_merge(...array_map(static fn (EmployeeBand $band): array => $band->brackets(), $this->bands));
            if ($this->includeUnknownSize) {
                $sizeBrackets[] = EmployeeBand::UNKNOWN_BRACKET;
            }
        }
        if (null !== $sizeBrackets) {
            $parameters['tranche_effectif_salarie'] = implode(',', $sizeBrackets);
        }

        $parameters = [...$parameters, ...$this->organisationType->apiParameters()];

        if (!$this->includeIndividuals) {
            $parameters['est_entrepreneur_individuel'] = 'false';
        }

        if (self::WHERE_COMMUNE === $this->where) {
            // Only the codes reach /near_point: everything else is ignored there, and checked on
            // each page instead (keeps()).
            return ['endpoint' => '/near_point', 'parameters' => array_filter([
                'activite_principale' => $parameters['activite_principale'] ?? null,
                'lat' => \sprintf('%.5F', (float) $this->latitude),
                'long' => \sprintf('%.5F', (float) $this->longitude),
                'radius' => (string) $this->radius,
            ], static fn (?string $value): bool => null !== $value)];
        }

        $parameters['departement'] = implode(',', $this->departments);

        return ['endpoint' => '/search', 'parameters' => $parameters];
    }

    /**
     * Whether a company the API returned answers the filters it could not apply itself - every
     * filter but the activity, around a commune. Around a département the API applied them all.
     */
    public function keeps(RegistryCompany $company): bool
    {
        if (self::WHERE_COMMUNE !== $this->where) {
            return true;
        }

        if (!$this->includeIndividuals && $company->individual) {
            return false;
        }

        if (!$this->organisationType->accepts($company->legalForm)) {
            return false;
        }

        if ([] !== $this->bands) {
            $bracket = $company->employeeBracket ?? EmployeeBand::UNKNOWN_BRACKET;
            $wanted = array_merge(...array_map(static fn (EmployeeBand $band): array => $band->brackets(), $this->bands));
            if ($this->includeUnknownSize) {
                $wanted[] = EmployeeBand::UNKNOWN_BRACKET;
            }

            return \in_array($bracket, $wanted, true);
        }

        return true;
    }

    /**
     * The query string that redraws this search - the pager's links and the « retour à la
     * recherche » of a fiche.
     *
     * @return array<string, string|int|list<string|int>>
     */
    public function toQuery(?int $page = null): array
    {
        $query = [
            'searched' => 1,
            'cat' => array_map(static fn (CompanySearchCategory $category): int => (int) $category->getId(), $this->categories),
            'naf' => implode(',', $this->nafCodes),
            'q' => $this->query,
            'where' => $this->where,
            'deps' => implode(', ', $this->departments),
            'size' => array_map(static fn (EmployeeBand $band): string => $band->value, $this->bands),
            'unknown' => $this->includeUnknownSize ? 1 : 0,
            'type' => $this->organisationType->value,
            'individuals' => $this->includeIndividuals ? 1 : 0,
            'page' => $page ?? $this->page,
        ];

        if (self::WHERE_COMMUNE === $this->where) {
            $query['commune'] = (string) $this->communeLabel;
            $query['lat'] = \sprintf('%.5F', (float) $this->latitude);
            $query['lon'] = \sprintf('%.5F', (float) $this->longitude);
            $query['radius'] = $this->radius;
        }

        return array_filter($query, static fn (mixed $value): bool => '' !== $value && [] !== $value);
    }

    /** @return list<string> */
    private function bracketsFrom(EmployeeBand $minimum): array
    {
        $brackets = [];
        $reached = false;
        foreach (EmployeeBand::cases() as $band) {
            $reached = $reached || $band === $minimum;
            if ($reached) {
                $brackets = [...$brackets, ...$band->brackets()];
            }
        }

        return $brackets;
    }
}
