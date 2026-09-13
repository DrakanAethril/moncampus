<?php

declare(strict_types=1);

namespace App\Tests\Service\Jobboard;

use App\Enum\JobboardBtsAccess;
use App\Enum\JobboardContract;
use App\Enum\JobboardCountry;
use App\Enum\JobboardLevelSource;
use App\Enum\JobboardRemote;
use App\Service\Jobboard\LegacyFileFormat;
use App\Service\Jobboard\OfferPayloadParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The format sheet published on « Configuration > Jobboard > Import », and the example file offered
 * beside it.
 *
 * **The example is the part worth testing.** A downloadable sample that the import refuses is worse
 * than no sample at all: it is read as proof that the screen is broken. So it goes through the very
 * parser the import uses, in legacy mode, exactly as an uploaded file would.
 *
 * The sheet itself is checked for completeness rather than wording: every source and every
 * enumerated value must appear, because the whole reason it is generated is that a hand-written one
 * loses a case the day one is added - and since the list of sites became a table, "a case is added"
 * means a row an administrator never touched.
 */
class LegacyFileFormatTest extends TestCase
{
    use SourceTableTrait;

    private const string NOW = '2026-09-13 09:00:00';

    public function testEveryOfferOfTheExampleGoesThroughTheImportsOwnParser(): void
    {
        $parser = new OfferPayloadParser(new MockClock(self::NOW), $this->resolver());

        foreach ($this->offers() as $index => $offer) {
            $payload = $parser->parse($offer, legacy: true);

            // The prefix is stripped, which is the one thing the legacy shape does differently.
            $this->assertStringNotContainsString('-', $payload->sourceRef, 'offre '.$index);
            $this->assertNotSame('', $payload->position);
        }
    }

    /** No publication date, and it must stay that way: an offer nobody dated is a normal offer. */
    public function testTheSecondOfferCarriesTheHolesARealAdvertHas(): void
    {
        $payload = (new OfferPayloadParser(new MockClock(self::NOW), $this->resolver()))->parse($this->offers()[1], legacy: true);

        $this->assertNull($payload->publishedAt);
        $this->assertTrue($payload->publishedAtApprox);
        $this->assertSame(JobboardLevelSource::Estime, $payload->levelSource);
        $this->assertSame(JobboardRemote::NonPrecise, $payload->remote);
    }

    /**
     * Dated off the clock and never in the future - the parser refuses a `date_publication` that is,
     * so a sample written with fixed dates would work until it did not.
     */
    public function testTheExampleIsNeverDatedInTheFuture(): void
    {
        $offers = $this->offers();

        $this->assertSame('2026-09-02', $offers[0]['date_publication']);
        $this->assertSame('2026-09-03', $offers[0]['date_reperage']);
        $this->assertSame('2026-09-10', $offers[1]['date_reperage']);
    }

    public function testTheSheetNamesEverySourceAndEveryEnumeratedValue(): void
    {
        $markdown = $this->format()->markdown();

        foreach ($this->sourceTable() as $source) {
            $this->assertStringContainsString($source->getLegacyPrefix().'-…', $markdown);
            $this->assertStringContainsString($source->getLabel(), $markdown);
        }

        $values = [
            ...JobboardContract::values(),
            ...JobboardCountry::values(),
            ...JobboardRemote::values(),
            ...JobboardLevelSource::values(),
            ...JobboardBtsAccess::values(),
        ];

        foreach ($values as $value) {
            $this->assertStringContainsString('`'.$value.'`', $markdown);
        }

        $this->assertStringContainsString((string) LegacyFileFormat::MAX_ROWS, $markdown);
    }

    /** @return list<array<array-key, mixed>> */
    private function offers(): array
    {
        $decoded = json_decode($this->format()->sample(), true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('offres', $decoded);
        $this->assertIsArray($decoded['offres']);
        $this->assertCount(2, $decoded['offres']);

        $offers = [];
        foreach ($decoded['offres'] as $offer) {
            $this->assertIsArray($offer);
            $offers[] = $offer;
        }

        return $offers;
    }

    private function format(): LegacyFileFormat
    {
        return new LegacyFileFormat(new MockClock(self::NOW), $this->sourceRepository());
    }
}
