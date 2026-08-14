<?php

declare(strict_types=1);

namespace App\Tests\Service\AlternanceImport;

use App\Service\AlternanceImport\ContractDateParser;
use PHPUnit\Framework\TestCase;

/**
 * Reading the two contract dates out of the school export's free-text "Observations" column.
 *
 * Every string tested here is copied verbatim from the 52 lines of the file this import was built
 * for - four spellings of the same thing, one line whose contract ends before it starts, and one
 * whose cell carries a trailing newline. They are the contract, not a wish list.
 *
 * The decisive ones are testRefusesAnImpossibleDate and the reversed pair: a lenient reader would
 * turn 31/02 into 3 March and accept a contract ending before it began, and both would then be
 * written as fact - the import's whole point is that a human sees them first.
 */
class ContractDateParserTest extends TestCase
{
    private ContractDateParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ContractDateParser();
    }

    public function testReadsThePlainForm(): void
    {
        $period = $this->parser->parse('01/09/2025 au 31/08/2026');

        self::assertNotNull($period);
        self::assertSame('01/09/2025', $period->start->format('d/m/Y'));
        self::assertSame('31/08/2026', $period->end->format('d/m/Y'));
        self::assertTrue($period->isChronological());
    }

    public function testIgnoresTheWordsAround(): void
    {
        $period = $this->parser->parse('Apprentissage du 02/02/2026 au 27/08/2027');

        self::assertNotNull($period);
        self::assertSame('02/02/2026', $period->start->format('d/m/Y'));
        self::assertSame('27/08/2027', $period->end->format('d/m/Y'));
    }

    public function testReadsDotsAndTwoDigitYears(): void
    {
        $period = $this->parser->parse('18.03.26 au 28.08.27');

        self::assertNotNull($period);
        self::assertSame('18/03/2026', $period->start->format('d/m/Y'));
        self::assertSame('28/08/2027', $period->end->format('d/m/Y'));
    }

    public function testReadsAMixOfYearLengths(): void
    {
        $period = $this->parser->parse('24/09/25 au 28/08/2027');

        self::assertNotNull($period);
        self::assertSame('24/09/2025', $period->start->format('d/m/Y'));
        self::assertSame('28/08/2027', $period->end->format('d/m/Y'));
    }

    public function testToleratesSurroundingWhitespace(): void
    {
        $period = $this->parser->parse("25/08/2025 au 09/08/2026\n");

        self::assertNotNull($period);
        self::assertSame('09/08/2026', $period->end->format('d/m/Y'));
    }

    public function testReportsAReversedPairRatherThanFixingIt(): void
    {
        // The real line 6 of the file: FAVIER Nathan, "08/10/2025 au 28/08/2025".
        $period = $this->parser->parse('08/10/2025 au 28/08/2025');

        self::assertNotNull($period);
        self::assertFalse($period->isChronological());
    }

    public function testRefusesASingleDate(): void
    {
        self::assertNull($this->parser->parse('à partir du 01/09/2025'));
    }

    public function testRefusesTextWithNoDate(): void
    {
        self::assertNull($this->parser->parse('en cours de signature'));
        self::assertNull($this->parser->parse(''));
    }

    public function testRefusesAnImpossibleDate(): void
    {
        self::assertNull($this->parser->parse('31/02/2026 au 28/08/2027'));
    }
}
