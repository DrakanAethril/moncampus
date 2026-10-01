<?php

declare(strict_types=1);

namespace App\Tests\Service\CompanySearch;

use App\Service\CompanySearch\CategoryStarterList;
use App\Service\CompanySearch\NafNomenclature;
use PHPUnit\Framework\TestCase;

/**
 * The INSEE's NAF rév. 2 as shipped in resources/naf/, and the starting list of categories that
 * must only ever name codes it holds - a mistyped code finds nothing, silently.
 */
class NafNomenclatureTest extends TestCase
{
    private NafNomenclature $nomenclature;

    protected function setUp(): void
    {
        $this->nomenclature = new NafNomenclature(\dirname(__DIR__, 3).'/resources/naf/naf-rev2.csv');
    }

    public function testItReadsLabelsWhateverTheCaseOfTheCode(): void
    {
        self::assertSame('Conseil en systèmes et logiciels informatiques', $this->nomenclature->label('62.02a'));
        self::assertNull($this->nomenclature->label('62.02X'));
        self::assertNull($this->nomenclature->label(null));
    }

    public function testItCompletesOnAWordWithoutItsAccents(): void
    {
        $codes = array_column($this->nomenclature->search('logiciel'), 'code');

        self::assertContains('58.29C', $codes);
        self::assertContains('62.02A', $codes);
        self::assertSame(['86.10Z'], array_column($this->nomenclature->search('activites hospitalieres'), 'code'));
        self::assertSame([], $this->nomenclature->search('   '));
    }

    public function testTheStartingListOnlyNamesRealCodes(): void
    {
        foreach (CategoryStarterList::categories() as $category) {
            foreach ($category->getNafCodes() as $code) {
                self::assertTrue($this->nomenclature->exists($code), $category->getLabel().' names '.$code);
            }
        }
    }
}
