<?php

declare(strict_types=1);

namespace App\Service\CompanySearch;

use App\Enum\CompanyOrganisationType;
use App\Enum\EmployeeBand;
use App\Repository\CompanySearchCategoryRepository;
use App\Service\QueryValue;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads « Trouver une entreprise »'s query string into a CompanySearchCriteria - the filters are in
 * the URL (a GET form), so a search is shared, bookmarked and found again with « Précédent ».
 *
 * Nothing typed reaches the API unchecked: a category is one that exists, a NAF code is one of the
 * nomenclature's, a département is a département code, a radius is one the screen offers.
 */
class CompanySearchCriteriaFactory
{
    public function __construct(
        private readonly CompanySearchCategoryRepository $categories,
        private readonly NafNomenclature $nomenclature,
        private readonly SchoolLocation $school,
    ) {
    }

    /**
     * The screen as it opens on a first visit: the school's département, every size, no « quoi ».
     */
    public function defaults(): CompanySearchCriteria
    {
        $department = $this->school->department();

        return new CompanySearchCriteria(departments: null !== $department ? [$department] : []);
    }

    public function fromRequest(Request $request): CompanySearchCriteria
    {
        if (!QueryValue::bool($request, 'searched')) {
            return $this->defaults();
        }

        $where = CompanySearchCriteria::WHERE_COMMUNE === QueryValue::string($request, 'where')
            ? CompanySearchCriteria::WHERE_COMMUNE
            : CompanySearchCriteria::WHERE_DEPARTMENTS;

        $radius = QueryValue::int($request, 'radius', 15);
        $latitude = $this->coordinate(QueryValue::string($request, 'lat'), 90.0);
        $longitude = $this->coordinate(QueryValue::string($request, 'lon'), 180.0);

        return new CompanySearchCriteria(
            categories: $this->categories->findByIds(QueryValue::intList($request, 'cat')),
            nafCodes: $this->nafCodes(QueryValue::string($request, 'naf')),
            query: mb_substr(QueryValue::trimmed($request, 'q'), 0, 100),
            where: $where,
            // Typed in one field (« 87, 19 »), or repeated (`dep[]=87`) by a hand-built link.
            departments: $this->departments([
                ...QueryValue::all($request, 'dep'),
                ...(preg_split('/[\s,;]+/', QueryValue::string($request, 'deps')) ?: []),
            ]),
            communeLabel: mb_substr(QueryValue::trimmed($request, 'commune'), 0, 120) ?: null,
            latitude: $latitude,
            longitude: $longitude,
            radius: \in_array($radius, CompanySearchCriteria::RADII, true) ? $radius : 15,
            bands: array_values(array_filter(array_map(
                static fn (mixed $value): ?EmployeeBand => \is_string($value) ? EmployeeBand::tryFrom($value) : null,
                QueryValue::all($request, 'size'),
            ))),
            includeUnknownSize: QueryValue::bool($request, 'unknown'),
            organisationType: CompanyOrganisationType::tryFrom(QueryValue::string($request, 'type')) ?? CompanyOrganisationType::Any,
            includeIndividuals: QueryValue::bool($request, 'individuals'),
            page: max(1, QueryValue::int($request, 'page', 1)),
            hideMine: QueryValue::bool($request, 'hide_mine'),
            map: 'map' === QueryValue::string($request, 'view'),
        );
    }

    /** @return list<string> */
    public function nafCodes(string $raw): array
    {
        $codes = [];
        foreach (preg_split('/[\s,;]+/', mb_strtoupper($raw)) ?: [] as $code) {
            if ('' !== $code && $this->nomenclature->exists($code) && !\in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return \array_slice($codes, 0, 30);
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return list<string>
     */
    private function departments(array $values): array
    {
        $departments = [];
        foreach ($values as $value) {
            $code = \is_scalar($value) ? mb_strtoupper(trim((string) $value)) : '';
            if (1 === preg_match('/^(0[1-9]|[1-8]\d|9[0-5]|2A|2B|97[1-6])$/', $code) && !\in_array($code, $departments, true)) {
                $departments[] = $code;
            }
        }

        return \array_slice($departments, 0, 10);
    }

    private function coordinate(string $raw, float $bound): ?float
    {
        if (!is_numeric($raw)) {
            return null;
        }

        $value = (float) $raw;

        return abs($value) <= $bound ? $value : null;
    }
}
