<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DataTableParams;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The paging and search parameters every DataTables endpoint reads.
 *
 * The clamping is the part worth pinning: the page length comes straight off the wire, so nothing
 * but this stops a request asking for a hundred thousand rows in one go.
 */
class DataTableParamsTest extends TestCase
{
    public function testReadsWhatDataTablesSends(): void
    {
        $params = DataTableParams::fromRequest($this->request([
            'draw' => '3',
            'start' => '20',
            'length' => '25',
            'search' => ['value' => 'dupont'],
            'includeInactive' => '1',
        ]));

        self::assertSame(3, $params->draw);
        self::assertSame(20, $params->start);
        self::assertSame(25, $params->length);
        self::assertSame('dupont', $params->search);
        self::assertTrue($params->includeInactive);
    }

    public function testAnEmptyRequestGetsTheDefaults(): void
    {
        $params = DataTableParams::fromRequest($this->request([]));

        self::assertSame(1, $params->draw);
        self::assertSame(0, $params->start);
        self::assertSame(10, $params->length);
        self::assertSame('', $params->search);
        self::assertFalse($params->includeInactive);
    }

    public function testThePageLengthIsCappedAtFifty(): void
    {
        // The only thing standing between the endpoint and a request for the whole table.
        self::assertSame(50, DataTableParams::fromRequest($this->request(['length' => '100000']))->length);
    }

    public function testANonPositiveLengthFallsBackToTheDefault(): void
    {
        // DataTables sends -1 for "show all"; this app does not offer that.
        self::assertSame(10, DataTableParams::fromRequest($this->request(['length' => '-1']))->length);
        self::assertSame(10, DataTableParams::fromRequest($this->request(['length' => '0']))->length);
    }

    public function testANegativeOffsetIsFlooredAtZero(): void
    {
        self::assertSame(0, DataTableParams::fromRequest($this->request(['start' => '-40']))->start);
    }

    public function testTheSearchTermIsTrimmed(): void
    {
        self::assertSame('dupont', DataTableParams::fromRequest($this->request(['search' => ['value' => '  dupont  ']]))->search);
    }

    public function testAMalformedSearchParameterReadsAsNoSearch(): void
    {
        // `search` is a nested array on the wire; anything else is not a search term.
        self::assertSame('', DataTableParams::fromRequest($this->request(['search' => ['value' => ['nested']]]))->search);
        self::assertSame('', DataTableParams::fromRequest($this->request(['search' => []]))->search);
    }

    public function testTheTupleKeepsTheOrderItsCallersDestructure(): void
    {
        $params = DataTableParams::fromRequest($this->request([
            'draw' => '2', 'start' => '10', 'length' => '20',
            'search' => ['value' => 'x'], 'includeInactive' => '1',
        ]));

        self::assertSame([2, 10, 20, 'x', true], $params->toList());
    }

    /** @param array<string, mixed> $query */
    private function request(array $query): Request
    {
        return new Request($query);
    }
}
