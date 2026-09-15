<?php

declare(strict_types=1);

namespace App\Tests\Service\Jobboard;

use App\Enum\JobboardContract;
use App\Enum\JobboardCountry;
use App\Enum\JobboardLevelSource;
use App\Enum\JobboardRemote;
use App\Service\Jobboard\JobboardRejection;
use App\Service\Jobboard\OfferPayloadParser;
use App\Service\Jobboard\OfferRejectedException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The boundary of the whole feature. Two halves matter equally here: what must be refused, and what
 * must **not** be - a parser that tightens an absent publication date or an empty city silently
 * drops real offers, and nobody would notice.
 */
class OfferPayloadParserTest extends TestCase
{
    use SourceTableTrait;

    private OfferPayloadParser $parser;

    protected function setUp(): void
    {
        $this->parser = new OfferPayloadParser(new MockClock('2026-09-12 10:00:00'), $this->resolver());
    }

    public function testItReadsACompleteOffer(): void
    {
        $payload = $this->parser->parse($this->offer());

        $this->assertSame('hellowork', $payload->source->getSlug());
        $this->assertSame('83313525', $payload->sourceRef);
        $this->assertSame(JobboardContract::Cdd, $payload->contract);
        $this->assertSame(JobboardCountry::France, $payload->country);
        $this->assertSame(JobboardRemote::NonPrecise, $payload->remote);
        $this->assertSame(JobboardLevelSource::Annonce, $payload->levelSource);
        $this->assertSame('79', $payload->departement);
        $this->assertSame('2026-09-12', $payload->publishedAt?->format('Y-m-d'));
    }

    /**
     * A `departement` is a string and stays one. "01" read as an integer comes back as "1", which is
     * a different département in every list it is compared against.
     */
    public function testItKeepsTheLeadingZeroOfADepartement(): void
    {
        $payload = $this->parser->parse($this->offer(['departement' => '01']));

        $this->assertSame('01', $payload->departement);
    }

    public function testItAcceptsCorsicaAndOverseas(): void
    {
        foreach (['2A', '2B', '971', '976'] as $departement) {
            $this->assertSame($departement, $this->parser->parse($this->offer(['departement' => $departement]))->departement);
        }
    }

    public function testItRefusesADepartementOutsideFrance(): void
    {
        $this->assertRejects(JobboardRejection::DepartementOutsideFrance, ['pays' => 'Canada', 'departement' => '33']);
    }

    public function testItRefusesADepartementOutOfFormat(): void
    {
        $this->assertRejects(JobboardRejection::InvalidDepartement, ['departement' => '999']);
    }

    public function testItRefusesAPublicationDateInTheFuture(): void
    {
        $this->assertRejects(JobboardRejection::PublishedInFuture, ['date_publication' => '2026-09-13']);
    }

    public function testItAcceptsTodayAsAPublicationDate(): void
    {
        $this->assertSame('2026-09-12', $this->parser->parse($this->offer(['date_publication' => '2026-09-12']))->publishedAt?->format('Y-m-d'));
    }

    /**
     * The check that used to refuse this offer, turned around. The URL is the truth and the
     * declared name is a hint, so an offer labelled `hellowork` on a Meteojob URL is filed under
     * Meteojob instead of being refused - and the offer survives, which is the whole point of
     * opening the list.
     */
    public function testTheUrlDecidesTheSourceWhateverTheNameSays(): void
    {
        $payload = $this->parser->parse($this->offer(['url' => 'https://www.meteojob.com/jobs/1']));

        $this->assertSame('meteojob', $payload->source->getSlug());
    }

    /**
     * The same rule read from the other end: a typo in the declared name costs nothing, because
     * nothing is decided on it when the host is known. Before, every offer of that batch was
     * refused one by one with `unknown_source`.
     */
    public function testATypoInTheDeclaredNameCannotForkASite(): void
    {
        $payload = $this->parser->parse($this->offer(['source' => 'hellowrk']));

        $this->assertSame('hellowork', $payload->source->getSlug());
    }

    /** A site nobody declared is created from its domain, never refused. */
    public function testAnUnknownSiteIsCreatedFromItsDomain(): void
    {
        $payload = $this->parser->parse($this->offer([
            'source' => 'Welcome to the Jungle',
            'url' => 'https://www.welcometothejungle.com/fr/companies/x/jobs/y',
        ]));

        $this->assertSame('welcometothejungle', $payload->source->getSlug());
        $this->assertSame('Welcome to the Jungle', $payload->source->getLabel());
        $this->assertSame(['welcometothejungle.com'], $payload->source->getDomains());
    }

    /** Two offers of the same new site, inside one batch, are one source and not two. */
    public function testANewSiteIsCreatedOnlyOnce(): void
    {
        $first = $this->parser->parse($this->offer(['source' => 'Indeed', 'url' => 'https://fr.indeed.com/viewjob?jk=1']));
        $second = $this->parser->parse($this->offer(['source' => 'Indeed', 'url' => 'https://fr.indeed.com/viewjob?jk=2']));

        $this->assertSame($first->source, $second->source);
    }

    /**
     * What survives of the old URL check: a link that is not a link. The value is rendered as an
     * `href` on the detail panel, so the scheme is part of the question.
     */
    public function testItRefusesAnUrlThatIsNotALink(): void
    {
        $this->assertRejects(JobboardRejection::InvalidUrl, ['url' => "voir sur le site de l'entreprise"]);
        $this->assertRejects(JobboardRejection::InvalidUrl, ['url' => 'javascript:alert(1)']);
    }

    public function testItAcceptsASubdomainOfTheSource(): void
    {
        $payload = $this->parser->parse($this->offer([
            'source' => 'francetravail',
            'url' => 'https://candidat.francetravail.fr/offres/recherche/detail/212WTTG',
        ]));

        $this->assertSame('francetravail', $payload->source->getSlug());
    }

    public function testItRefusesAnAbsentLevelSource(): void
    {
        $data = $this->offer();
        unset($data['niveau_source']);

        $this->expectException(OfferRejectedException::class);
        $this->parser->parse($data);
    }

    public function testItRefusesValuesOutsideTheirEnumeration(): void
    {
        $this->assertRejects(JobboardRejection::UnknownContract, ['contrat' => 'freelance']);
        $this->assertRejects(JobboardRejection::UnknownCountry, ['pays' => 'Allemagne']);
        $this->assertRejects(JobboardRejection::UnknownRemote, ['teletravail' => 'parfois']);
    }

    /**
     * The other half of the contract, and the half that gets broken by over-zealous validation:
     * these are normal situations and none of them may cost an offer.
     */
    public function testItDoesNotRefuseTheNormalGaps(): void
    {
        $payload = $this->parser->parse($this->offer([
            'ville' => '',
            'region' => '',
            'note' => '',
            'entreprise' => 'Non précisée',
            'categorie' => 'quelque-chose-de-neuf',
            'date_publication' => null,
        ]));

        $this->assertNull($payload->city);
        $this->assertNull($payload->region);
        $this->assertNull($payload->note);
        $this->assertNull($payload->publishedAt);
        $this->assertSame('Non précisée', $payload->company);
        // Free text, never an enumeration: the data contract lists what must be refused and
        // `categorie` is deliberately not in it.
        $this->assertSame('quelque-chose-de-neuf', $payload->category);
    }

    public function testAnOfferWithNoDateIsApproximateWhateverItSays(): void
    {
        $data = $this->offer(['date_publication' => null]);
        unset($data['date_publication_approx']);

        $this->assertTrue($this->parser->parse($data)->publishedAtApprox);
    }

    public function testItRefusesARawPayloadOverTheCap(): void
    {
        $this->assertRejects(JobboardRejection::RawTooLarge, ['brut' => ['x' => str_repeat('a', OfferPayloadParser::MAX_RAW_BYTES + 1)]]);
    }

    /**
     * The legacy file, read as it is: display-cased values, accented enumerations, `date_reperage`
     * as the first-seen stamp, and `source_ref` recovered from the prefixed id.
     */
    public function testItReadsTheLegacyShape(): void
    {
        $payload = $this->parser->parse([
            'id' => 'hw-83313525',
            'source' => 'HelloWork',
            'poste' => 'Technicien informatique',
            'entreprise' => 'Astek',
            'ville' => 'Nantes',
            'departement' => '44',
            'contrat' => 'CDI',
            'niveau' => 'Non précisé',
            'categorie' => 'SISR',
            'acces_bts' => 'accessible',
            'date_publication' => '2026-09-12',
            'date_approx' => true,
            'date_reperage' => '2026-09-12',
            'url' => 'https://www.hellowork.com/fr-fr/emplois/83313525.html',
            'teletravail' => 'non précisé',
            'region' => 'Pays de la Loire',
            'pays' => 'France',
            'niveau_source' => 'estimé',
        ], legacy: true);

        $this->assertSame('83313525', $payload->sourceRef);
        $this->assertSame('hellowork', $payload->source->getSlug());
        $this->assertSame(JobboardContract::Cdi, $payload->contract);
        $this->assertSame('sisr', $payload->category);
        $this->assertSame(JobboardRemote::NonPrecise, $payload->remote);
        $this->assertSame(JobboardLevelSource::Estime, $payload->levelSource);
        $this->assertTrue($payload->publishedAtApprox);
        $this->assertSame('2026-09-12', $payload->firstSeenAt?->format('Y-m-d'));
    }

    /**
     * The trap named in design/validated/jobboard.md §8.2: for Jobteaser and RemoteFR the legacy id
     * is a *truncation* of the site's identifier. The import keeps the truncation rather than
     * inventing something else, and the published instructions ask the agent for the same value -
     * otherwise the same offer would be filed twice and the first-seen date lost.
     */
    public function testItKeepsTheTruncatedLegacyReferences(): void
    {
        $payload = $this->parser->parse([
            'id' => 'jt-e2ec328c',
            'source' => 'Jobteaser',
            'poste' => 'Alternant',
            'entreprise' => 'Une entreprise',
            'contrat' => 'Alternance',
            'niveau' => 'Bac+2',
            'acces_bts' => 'accessible',
            'teletravail' => 'partiel',
            'pays' => 'France',
            'niveau_source' => 'annonce',
            'url' => 'https://www.jobteaser.com/fr/job-offers/e2ec328c-1474-44ec-bd20-89094bd2c5de',
        ], legacy: true);

        $this->assertSame('e2ec328c', $payload->sourceRef);
    }

    /**
     * A company open to unsolicited applications publishes no advert, so there is no title to read.
     * It is the one contract where `poste` may be left out, and the entry is filed under a title of
     * the platform's own rather than refused.
     */
    public function testItNamesASpontaneousApplicationWhenThePayloadCarriesNoPosition(): void
    {
        $payload = $this->parser->parse($this->offer([
            'contrat' => 'spontanee',
            'poste' => '',
            'date_publication' => null,
        ]));

        $this->assertSame(JobboardContract::Spontanee, $payload->contract);
        $this->assertSame('Candidature spontanée', $payload->position);
    }

    /** What the agent found on the company's page wins over the platform's fallback. */
    public function testItKeepsThePositionSentWithASpontaneousApplication(): void
    {
        $payload = $this->parser->parse($this->offer([
            'contrat' => 'spontanee',
            'poste' => 'Profils réseaux et systèmes',
        ]));

        $this->assertSame('Profils réseaux et systèmes', $payload->position);
    }

    /** Every other contract still owes a title: the fallback belongs to one case, not to all. */
    public function testItStillRefusesAnOfferWithoutAPosition(): void
    {
        $this->assertRejects(JobboardRejection::MissingField, ['poste' => '']);
    }

    /** @param array<string, mixed> $overrides */
    private function assertRejects(JobboardRejection $reason, array $overrides): void
    {
        try {
            $this->parser->parse($this->offer($overrides));
            $this->fail('The offer should have been refused with '.$reason->value.'.');
        } catch (OfferRejectedException $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function offer(array $overrides = []): array
    {
        return array_merge([
            'source' => 'hellowork',
            'source_ref' => '83313525',
            'url' => 'https://www.hellowork.com/fr-fr/emplois/83313525.html',
            'poste' => 'Technicien / Technicienne informatique',
            'entreprise' => 'Lycée St André Notre Dame',
            'categorie' => 'sisr',
            'contrat' => 'cdd',
            'pays' => 'France',
            'region' => 'Nouvelle-Aquitaine',
            'departement' => '79',
            'ville' => 'Niort',
            'niveau' => 'Bac+2 à Bac+4',
            'niveau_source' => 'annonce',
            'acces_bts' => 'accessible',
            'teletravail' => 'non_precise',
            'date_publication' => '2026-09-12',
            'date_publication_approx' => false,
            'note' => 'Débutant accepté',
        ], $overrides);
    }
}
