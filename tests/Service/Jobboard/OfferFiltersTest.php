<?php

declare(strict_types=1);

namespace App\Tests\Service\Jobboard;

use App\Enum\JobboardContract;
use App\Service\Jobboard\OfferFilters;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reading the filter bar off the query string.
 *
 * The first test is the one that matters: a filter bar whose « Toutes » option is `value=""`
 * submits `?dept=` on every single interaction, and `InputBag::getInt()` answers a **400** to the
 * empty string. Four screens of this application died that way on the same afternoon, which is why
 * everything here goes through App\Service\QueryValue.
 */
class OfferFiltersTest extends TestCase
{
    public function testAnEmptyValueIsNotAFilterAndIsNotAnError(): void
    {
        $filters = OfferFilters::fromRequest(Request::create('/jobboard?dept=&vue=&contrat=&categorie=&filiere='));

        $this->assertTrue($filters->isEmpty());
        $this->assertNull($filters->departement);
        $this->assertNull($filters->firstSeenFrom);
        $this->assertSame([], $filters->contracts);
        $this->assertSame([], $filters->trackIds);
    }

    public function testItReadsACommaSeparatedList(): void
    {
        $filters = OfferFilters::fromRequest(Request::create('/jobboard?contrat=cdi,stage'));

        $this->assertSame([JobboardContract::Cdi, JobboardContract::Stage], $filters->contracts);
        $this->assertFalse($filters->isEmpty());
    }

    public function testItDropsValuesOutsideTheirEnumeration(): void
    {
        $filters = OfferFilters::fromRequest(Request::create('/jobboard?contrat=cdi,freelance'));

        $this->assertSame([JobboardContract::Cdi], $filters->contracts);
    }

    /**
     * The sources are the one multi-valued filter with nothing to check them against: the list of
     * sites is a table now, so a slug that matches no row simply selects nothing. Filtering them
     * here would mean re-reading that table on every request to answer a question the `IN` clause
     * already answers.
     */
    public function testSourceSlugsTravelAsTheyAreWritten(): void
    {
        $filters = OfferFilters::fromRequest(Request::create('/jobboard?source=hellowork,Indeed'));

        $this->assertSame(['hellowork', 'indeed'], $filters->sources);
    }

    public function testCategoriesAreLoweredSoTheyMatchWhatIsStored(): void
    {
        $filters = OfferFilters::fromRequest(Request::create('/jobboard?categorie=SISR'));

        $this->assertSame(['sisr'], $filters->categories);
    }

    public function testAMalformedDateIsIgnoredRatherThanRefused(): void
    {
        $filters = OfferFilters::fromRequest(Request::create('/jobboard?vue=pas-une-date'));

        $this->assertNull($filters->firstSeenFrom);
    }

    /**
     * The three administrator-only filters are cleared server-side rather than merely left out of
     * the interface: typing one into the address bar must change nothing.
     */
    public function testTheAdminOnlyFiltersAreDroppedForEverybodyElse(): void
    {
        $filters = OfferFilters::fromRequest(Request::create('/jobboard?source=hellowork&acces=accessible&contrat=cdi'))
            ->withoutAdminFilters();

        $this->assertSame([], $filters->sources);
        $this->assertSame([], $filters->btsAccess);
        $this->assertSame([JobboardContract::Cdi], $filters->contracts);
    }
}
