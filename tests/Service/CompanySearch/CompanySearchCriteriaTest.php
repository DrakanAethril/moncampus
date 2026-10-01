<?php

declare(strict_types=1);

namespace App\Tests\Service\CompanySearch;

use App\Entity\CompanySearchCategory;
use App\Enum\CompanyCategoryFlag;
use App\Enum\CompanyOrganisationType;
use App\Enum\EmployeeBand;
use App\Service\CompanySearch\CompanySearchCriteria;
use App\Service\Sirene\RegistryCompany;
use PHPUnit\Framework\TestCase;

/**
 * What « Trouver une entreprise » sends to the register, and what it refuses to send
 * (design/validated/vivier-entreprises.md §8.1, measured against the API on 2026-09-30/10-01).
 */
class CompanySearchCriteriaTest extends TestCase
{
    public function testADepartmentSearchSendsEveryFilterAndTheHiddenOnes(): void
    {
        $criteria = new CompanySearchCriteria(
            categories: [$this->codes(['62.02A', '62.01Z']), $this->codes(['58.29C', '62.01Z'])],
            nafCodes: ['63.11Z'],
            query: 'conseil',
            departments: ['87', '19'],
            bands: [EmployeeBand::Micro],
            organisationType: CompanyOrganisationType::Association,
        );

        self::assertNull($criteria->problem());
        $request = $criteria->toApiRequest();

        self::assertSame('/search', $request['endpoint']);
        self::assertSame([
            'etat_administratif' => 'A',
            'activite_principale' => '58.29C,62.01Z,62.02A,63.11Z',
            'q' => 'conseil',
            'tranche_effectif_salarie' => '01,02,03,NN',
            'est_association' => 'true',
            'est_entrepreneur_individuel' => 'false',
            'departement' => '87,19',
        ], $request['parameters']);
    }

    public function testNoSizeBoxMeansEverySizeAndIndividualsCanBeIncluded(): void
    {
        $parameters = (new CompanySearchCriteria(categories: [$this->codes(['62.02A'])], departments: ['87'], includeIndividuals: true))
            ->toApiRequest()['parameters'];

        self::assertArrayNotHasKey('tranche_effectif_salarie', $parameters);
        self::assertArrayNotHasKey('est_entrepreneur_individuel', $parameters);
    }

    public function testAFlagOrASizeCategoryIsSearchedOnItsOwn(): void
    {
        $collectivities = (new CompanySearchCategory())->setLabel('Collectivités')->setFlag(CompanyCategoryFlag::LocalAuthority);
        $large = (new CompanySearchCategory())->setLabel('Grandes entreprises')->setMinimumBand(EmployeeBand::Large);

        $alone = new CompanySearchCriteria(categories: [$collectivities], departments: ['87']);
        self::assertNull($alone->problem());
        self::assertSame('true', $alone->toApiRequest()['parameters']['est_collectivite_territoriale']);

        self::assertSame(
            '32,41,42,51,52,53',
            (new CompanySearchCriteria(categories: [$large], departments: ['87']))->toApiRequest()['parameters']['tranche_effectif_salarie'],
        );

        self::assertSame('companySearchSoloCategoryError', (new CompanySearchCriteria(categories: [$collectivities, $this->codes(['62.02A'])], departments: ['87']))->problem());
        self::assertSame('companySearchSoloCategoryError', (new CompanySearchCriteria(categories: [$collectivities, $large], departments: ['87']))->problem());
    }

    public function testASearchNeedsAWhatAndAWhere(): void
    {
        self::assertSame('companySearchNoWhatError', (new CompanySearchCriteria(departments: ['87']))->problem());
        self::assertSame('companySearchNoDepartmentError', (new CompanySearchCriteria(query: 'x'))->problem());
        self::assertSame('companySearchNoCommuneError', (new CompanySearchCriteria(query: 'x', where: CompanySearchCriteria::WHERE_COMMUNE))->problem());
    }

    /**
     * /near_point filters on activity codes and nothing else (it refuses `q`, ignores the rest):
     * only the codes are sent, the other filters are checked on each company of the page.
     */
    public function testAroundACommuneOnlyTheCodesAreSentAndTheRestIsCheckedOnThePage(): void
    {
        $criteria = new CompanySearchCriteria(
            categories: [$this->codes(['62.02A'])],
            where: CompanySearchCriteria::WHERE_COMMUNE,
            latitude: 45.80308,
            longitude: 1.20139,
            radius: 10,
            bands: [EmployeeBand::Medium],
            includeUnknownSize: false,
            organisationType: CompanyOrganisationType::Private,
        );

        self::assertSame(['endpoint' => '/near_point', 'parameters' => [
            'activite_principale' => '62.02A', 'lat' => '45.80308', 'long' => '1.20139', 'radius' => '10',
        ]], $criteria->toApiRequest());

        self::assertTrue($criteria->keeps($this->company('12', '5710', false)));
        self::assertFalse($criteria->keeps($this->company('12', '1000', true)), 'a sole trader');
        self::assertFalse($criteria->keeps($this->company('12', '9220', false)), 'an association');
        self::assertFalse($criteria->keeps($this->company('41', '5710', false)), 'too large');
        self::assertFalse($criteria->keeps($this->company(null, '5710', false)), 'unknown size, not kept');

        self::assertSame('companySearchCommuneCodesOnlyError', (new CompanySearchCriteria(
            query: 'conseil', where: CompanySearchCriteria::WHERE_COMMUNE, latitude: 45.8, longitude: 1.2,
        ))->problem());
    }

    public function testAroundADepartmentTheApiAlreadyFilteredEverything(): void
    {
        self::assertTrue((new CompanySearchCriteria(departments: ['87']))->keeps($this->company('41', '1000', true)));
    }

    public function testTheQueryRedrawsTheSearch(): void
    {
        $query = (new CompanySearchCriteria(nafCodes: ['62.02A'], departments: ['87', '19'], page: 3))->toQuery(4);

        self::assertSame(1, $query['searched']);
        self::assertSame('62.02A', $query['naf']);
        self::assertSame('87, 19', $query['deps']);
        self::assertSame(4, $query['page']);
        self::assertArrayNotHasKey('lat', $query);
    }

    /** @param list<string> $codes */
    private function codes(array $codes): CompanySearchCategory
    {
        return (new CompanySearchCategory())->setLabel(implode(' ', $codes))->setNafCodes($codes);
    }

    private function company(?string $bracket, string $legalForm, bool $individual): RegistryCompany
    {
        return new RegistryCompany('123456789', 'X', '62.02A', $bracket, null, null, [], $individual, $legalForm);
    }
}
