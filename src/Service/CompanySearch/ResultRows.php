<?php

declare(strict_types=1);

namespace App\Service\CompanySearch;

use App\Enum\EmployeeBand;
use App\Service\Sirene\EstablishmentCandidate;
use App\Service\Sirene\RegistryCompany;

/**
 * Turns what the register said into what « Trouver une entreprise » draws: an activity in words,
 * a headcount in words, a distance. One row per company, its open establishments of the zone
 * underneath - a student writes to a place, but recognises a company.
 *
 * @phpstan-type EstablishmentRow array{
 *     siret: string, address: string, city: ?string, postalCode: ?string, headOffice: bool,
 *     distanceKm: ?float, size: ?array{key: string, params: array<string, string>},
 *     latitude: ?float, longitude: ?float, open: bool, createdOn: ?\DateTimeImmutable,
 *     closedOn: ?\DateTimeImmutable, activityLabel: ?string
 * }
 * @phpstan-type CompanyRow array{
 *     siren: string, name: string, otherNames: list<string>, activityCode: ?string,
 *     activityLabel: ?string, size: array{key: string, params: array<string, string>},
 *     category: ?string, individual: bool, headOffice: ?EstablishmentRow,
 *     establishments: list<EstablishmentRow>
 * }
 */
class ResultRows
{
    public function __construct(
        private readonly NafNomenclature $nomenclature,
    ) {
    }

    /**
     * @param array{float, float}|null $origin where distances are measured from
     *
     * @return CompanyRow
     */
    public function company(RegistryCompany $company, ?array $origin): array
    {
        $first = $company->establishments[0];

        return [
            'siren' => $company->siren,
            'name' => $company->fullName,
            'otherNames' => $first->otherNames(),
            'activityCode' => $company->activityCode,
            'activityLabel' => $this->nomenclature->label($company->activityCode),
            'size' => EmployeeBand::bracketLabel($company->employeeBracket),
            'category' => $company->category,
            'individual' => $company->individual,
            'headOffice' => null !== $company->headOffice ? $this->establishment($company->headOffice, $origin) : null,
            'establishments' => array_map(fn (EstablishmentCandidate $establishment): array => $this->establishment($establishment, $origin), $company->establishments),
        ];
    }

    /**
     * @param array{float, float}|null $origin
     *
     * @return EstablishmentRow
     */
    public function establishment(EstablishmentCandidate $establishment, ?array $origin): array
    {
        $distance = null;
        if (null !== $origin && null !== $establishment->latitude && null !== $establishment->longitude) {
            $distance = Distance::km($origin[0], $origin[1], $establishment->latitude, $establishment->longitude);
        }

        return [
            'siret' => $establishment->siret,
            'address' => $establishment->address,
            'city' => $establishment->city,
            'postalCode' => $establishment->postalCode,
            'headOffice' => $establishment->headOffice,
            'distanceKm' => $distance,
            'size' => null !== $establishment->employeeBracket && EmployeeBand::UNKNOWN_BRACKET !== $establishment->employeeBracket
                ? EmployeeBand::bracketLabel($establishment->employeeBracket)
                : null,
            'latitude' => $establishment->latitude,
            'longitude' => $establishment->longitude,
            'open' => $establishment->open,
            'createdOn' => $establishment->createdOn,
            'closedOn' => $establishment->closedOn,
            'activityLabel' => $this->nomenclature->label($establishment->activityCode),
        ];
    }
}
