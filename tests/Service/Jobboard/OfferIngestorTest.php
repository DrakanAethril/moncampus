<?php

declare(strict_types=1);

namespace App\Tests\Service\Jobboard;

use App\Entity\JobboardBatch;
use App\Entity\JobboardOffer;
use App\Entity\JobboardSource;
use App\Entity\JobboardToken;
use App\Entity\Section;
use App\Entity\Track;
use App\Enum\JobboardContract;
use App\Enum\JobboardLearningKind;
use App\Enum\JobboardLevelSource;
use App\Enum\JobboardRemote;
use App\Repository\JobboardOfferRepository;
use App\Service\Jobboard\IngestOutcome;
use App\Service\Jobboard\IngestReport;
use App\Service\Jobboard\JobboardRejection;
use App\Service\Jobboard\OfferIngestor;
use App\Service\Jobboard\OfferPayloadParser;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The rules that decide what an offer becomes when it comes back.
 *
 * This is where the value of the whole feature sits, and it is all asymmetry: a second, more
 * thorough pass enriches a row, a hurried one never degrades it. And above everything else,
 * `premiere_vue` never moves - no site can give it back.
 */
class OfferIngestorTest extends TestCase
{
    use SourceTableTrait;

    private Track $track;

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->track = new Track('BTS SIO', new Section('Enseignement supérieur'));
        $this->clock = new MockClock('2026-09-12 10:00:00');
    }

    public function testAnUnknownOfferIsCreatedAndStampedNow(): void
    {
        $report = $this->ingest(null, $this->offer());

        $this->assertSame(1, $report->created());
        $this->assertSame(IngestOutcome::Created, $report->lines[0]->outcome);
    }

    public function testAKnownOfferIsReviewedAndItsFirstSeenDateNeverMoves(): void
    {
        $stored = $this->stored();
        $before = $stored->getFirstSeenAt();

        $this->clock->modify('+3 days');
        $report = $this->ingest($stored, $this->offer(['poste' => 'Technicien réseau']));

        $this->assertSame(1, $report->reviewed());
        $this->assertEquals($before, $stored->getFirstSeenAt());
        $this->assertSame('2026-09-15', $stored->getLastSeenAt()->format('Y-m-d'));
        $this->assertSame('Technicien réseau', $stored->getPosition());
    }

    public function testALevelReadOnTheAdvertReplacesAnEstimatedOne(): void
    {
        $stored = $this->stored();
        $stored->setLevel('Bac+2')->setLevelSource(JobboardLevelSource::Estime);

        $this->ingest($stored, $this->offer(['niveau' => 'Bac+3 à Bac+5', 'niveau_source' => 'annonce']));

        $this->assertSame('Bac+3 à Bac+5', $stored->getLevel());
        $this->assertSame(JobboardLevelSource::Annonce, $stored->getLevelSource());
    }

    public function testAnEstimatedLevelNeverReplacesOneReadOnTheAdvert(): void
    {
        $stored = $this->stored();
        $stored->setLevel('Bac+2')->setLevelSource(JobboardLevelSource::Annonce);

        $this->ingest($stored, $this->offer(['niveau' => 'Non précisé', 'niveau_source' => 'estime']));

        $this->assertSame('Bac+2', $stored->getLevel());
        $this->assertSame(JobboardLevelSource::Annonce, $stored->getLevelSource());
    }

    public function testAnExactDateReplacesAnApproximateOne(): void
    {
        $stored = $this->stored();
        $stored->setPublication(new \DateTimeImmutable('2026-09-01'), true);

        $this->ingest($stored, $this->offer(['date_publication' => '2026-08-01', 'date_publication_approx' => false]));

        $this->assertSame('2026-08-01', $stored->getPublishedAt()?->format('Y-m-d'));
        $this->assertFalse($stored->isPublishedAtApprox());
    }

    /**
     * The trap the data contract names: several sites republish old adverts with a fresh « il y a
     * 2 heures ». That value must never overwrite a date somebody read on the offer's own page.
     */
    public function testAnApproximateDateNeverReplacesAnExactOne(): void
    {
        $stored = $this->stored();
        $stored->setPublication(new \DateTimeImmutable('2026-08-01'), false);

        $this->ingest($stored, $this->offer(['date_publication' => '2026-09-12', 'date_publication_approx' => true]));

        $this->assertSame('2026-08-01', $stored->getPublishedAt()?->format('Y-m-d'));
        $this->assertFalse($stored->isPublishedAtApprox());
    }

    public function testAPassWithNoDateErasesNothing(): void
    {
        $stored = $this->stored();
        $stored->setPublication(new \DateTimeImmutable('2026-08-01'), false);

        $this->ingest($stored, $this->offer(['date_publication' => null]));

        $this->assertSame('2026-08-01', $stored->getPublishedAt()?->format('Y-m-d'));
    }

    public function testAStatedRemoteValueReplacesNonPrecise(): void
    {
        $stored = $this->stored();

        $this->ingest($stored, $this->offer(['teletravail' => 'total']));

        $this->assertSame(JobboardRemote::Total, $stored->getRemote());
    }

    /** « non précisé » is not « aucun », and it must never overwrite an answer somebody found. */
    public function testNonPreciseNeverReplacesAStatedRemoteValue(): void
    {
        $stored = $this->stored();
        $stored->setRemote(JobboardRemote::Partiel);

        $this->ingest($stored, $this->offer(['teletravail' => 'non_precise']));

        $this->assertSame(JobboardRemote::Partiel, $stored->getRemote());
    }

    public function testAnOfferAlreadyClosedIsNotReopenedByComingBack(): void
    {
        $stored = $this->stored();
        $stored->close(new \DateTimeImmutable('2026-09-05'));

        $this->ingest($stored, $this->offer());

        $this->assertTrue($stored->isClosed());
    }

    public function testAMalformedOfferDoesNotTakeTheOthersWithIt(): void
    {
        $report = $this->ingest(null, $this->offer(), $this->offer(['contrat' => 'freelance', 'source_ref' => '2']));

        $this->assertSame(1, $report->created());
        $this->assertSame(1, $report->rejected());
        $this->assertSame(JobboardRejection::UnknownContract, $report->rejectedLines()[0]->reason);
    }

    public function testTheSameOfferTwiceInOneCallIsRefusedOnce(): void
    {
        $report = $this->ingest(null, $this->offer(), $this->offer());

        $this->assertSame(1, $report->created());
        $this->assertSame(JobboardRejection::DuplicateInBatch, $report->rejectedLines()[0]->reason);
    }

    /**
     * A site nobody had declared is filed, not refused - and the pass says so. The count of offers
     * does not move: one creation can serve four hundred of them, so it is a fact about the deposit
     * and never an outcome of a line.
     */
    public function testADepositSaysWhichSiteItInvented(): void
    {
        $report = $this->ingest(null, $this->offer([
            'source' => 'Welcome to the Jungle',
            'url' => 'https://fr.welcometothejungle.com/jobs/1',
        ]));

        $this->assertSame(1, $report->created());
        $this->assertCount(1, $report->learned);
        $this->assertSame(JobboardLearningKind::SourceCreated, $report->learned[0]->kind);
        $this->assertSame('welcometothejungle.com', $report->learned[0]->domain);
        $this->assertSame(['sources' => [[
            'kind' => 'source_created',
            'source' => 'welcometothejungle',
            'declared' => 'Welcome to the Jungle',
            'domain' => 'welcometothejungle.com',
        ]]], array_intersect_key($report->toArray(), ['sources' => null]));
    }

    /**
     * The gesture that deserves a screen: a known name on an unknown host glues that host's domain
     * onto the site, and every later offer from behind it files there without a word.
     */
    public function testADepositSaysWhichDomainItAttached(): void
    {
        $report = $this->ingest(null, $this->offer([
            'source' => 'hellowork',
            'url' => 'https://bit.ly/an-offer',
        ]));

        $this->assertSame(1, $report->created());
        $this->assertCount(1, $report->learned);
        $this->assertSame(JobboardLearningKind::DomainAttached, $report->learned[0]->kind);
        $this->assertSame('hellowork', $report->learned[0]->source->getSlug());
        $this->assertSame('bit.ly', $report->learned[0]->domain);
        $this->assertSame('hellowork', $report->learned[0]->declared);
    }

    /** An ordinary pass decides nothing, and must not print a diagnostic saying it did. */
    public function testADepositOnAKnownHostLearnsNothing(): void
    {
        $this->assertSame([], $this->ingest(null, $this->offer())->learned);
    }

    /** Forty offers of one new site are one creation, on screen as in the table. */
    public function testTheSameNewSiteIsLearnedOnce(): void
    {
        $report = $this->ingest(null,
            $this->offer(['source' => 'Indeed', 'source_ref' => '1', 'url' => 'https://fr.indeed.com/1']),
            $this->offer(['source' => 'Indeed', 'source_ref' => '2', 'url' => 'https://fr.indeed.com/2']),
        );

        $this->assertSame(2, $report->created());
        $this->assertCount(1, $report->learned);
    }

    /**
     * The dry run of the import screen announces what the real pass will learn and writes nothing -
     * neither the site it named nor the record of having named it.
     */
    public function testTheDryRunAnnouncesWhatItWillLearnAndPersistsNothing(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');

        $report = $this->ingestor(null, $entityManager)->analyse($this->track, [$this->offer([
            'source' => 'Welcome to the Jungle',
            'url' => 'https://fr.welcometothejungle.com/jobs/1',
        ])]);

        $this->assertCount(1, $report->learned);
        $this->assertSame(JobboardLearningKind::SourceCreated, $report->learned[0]->kind);
    }

    /**
     * A company open to unsolicited applications is not an advert: nothing dates it, and nothing
     * republishes it. The day it was spotted stands in, marked approximate because that is what it
     * is - and the alternative, an empty column on an entry that is always current, says less.
     */
    public function testASpontaneousApplicationIsDatedFromTheDayItWasSpotted(): void
    {
        $created = $this->capture(null, $this->offer([
            'contrat' => 'spontanee',
            'date_publication' => null,
        ]));

        $this->assertSame('2026-09-12', $created->getPublishedAt()?->format('Y-m-d'));
        $this->assertTrue($created->isPublishedAtApprox());
    }

    /**
     * The trap this rule had to avoid. On review an approximate date replaces an approximate date,
     * so a stamp recomputed on every pass would walk forward day after day, and an entry collected
     * since July would read as published this morning.
     */
    public function testTheStampedDateDoesNotMoveOnALaterPass(): void
    {
        $stored = $this->stored();
        $stored->setContract(JobboardContract::Spontanee)->setPublication(new \DateTimeImmutable('2026-07-01'), true);

        $this->clock->modify('+3 days');
        $this->ingest($stored, $this->offer(['contrat' => 'spontanee', 'date_publication' => null]));

        $this->assertSame('2026-07-01', $stored->getPublishedAt()?->format('Y-m-d'));
    }

    /**
     * A row that carries no date yet - created before the rule existed, or reclassified by the
     * veille on this very pass - is settled from its own first-seen date, never from today.
     */
    public function testAnUndatedRowIsSettledFromItsOwnFirstSeenDate(): void
    {
        $stored = $this->stored();

        $this->clock->modify('+3 days');
        $this->ingest($stored, $this->offer(['contrat' => 'spontanee', 'date_publication' => null]));

        $this->assertSame('2026-07-01', $stored->getPublishedAt()?->format('Y-m-d'));
        $this->assertTrue($stored->isPublishedAtApprox());
    }

    /** A date the veille did find on the page wins over the platform's stamp. */
    public function testADateReadOnThePageWinsOverTheStamp(): void
    {
        $created = $this->capture(null, $this->offer([
            'contrat' => 'spontanee',
            'date_publication' => '2026-09-01',
            'date_publication_approx' => false,
        ]));

        $this->assertSame('2026-09-01', $created->getPublishedAt()?->format('Y-m-d'));
        $this->assertFalse($created->isPublishedAtApprox());
    }

    /** Every other contract keeps an empty publication date: half the sources never carry one. */
    public function testAnOrdinaryOfferWithoutADateStaysUndated(): void
    {
        $created = $this->capture(null, $this->offer(['date_publication' => null]));

        $this->assertNull($created->getPublishedAt());
    }

    /** @param array<string, mixed> $row */
    private function capture(?JobboardOffer $stored, array $row): JobboardOffer
    {
        $persisted = null;
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            if ($entity instanceof JobboardOffer) {
                $persisted = $entity;
            }
        });

        $this->ingestor($stored, $entityManager)->ingest($this->batch(), [$row]);

        $this->assertInstanceOf(JobboardOffer::class, $persisted);

        return $persisted;
    }

    /** @param array<string, mixed> ...$rows */
    private function ingest(?JobboardOffer $stored, array ...$rows): IngestReport
    {
        return $this->ingestor($stored)->ingest($this->batch(), array_values($rows));
    }

    /**
     * One resolver for the parser and for the ingestor, because the journal of what a pass learned
     * lives on it: two instances would parse against one table and drain the other, which is
     * exactly the bug this shape prevents.
     */
    private function ingestor(?JobboardOffer $stored, ?EntityManagerInterface $entityManager = null): OfferIngestor
    {
        $repository = $this->createStub(JobboardOfferRepository::class);
        $repository->method('findOneByIdentity')->willReturn($stored);
        $resolver = $this->resolver();

        return new OfferIngestor(
            new OfferPayloadParser($this->clock, $resolver),
            $repository,
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            $this->clock,
            $resolver,
        );
    }

    private function batch(): JobboardBatch
    {
        return JobboardBatch::forToken(new JobboardToken('Veille', $this->track, 'selector', 'hash', null));
    }

    private function stored(): JobboardOffer
    {
        return new JobboardOffer(
            $this->track,
            new JobboardSource('hellowork', 'HelloWork', ['hellowork.com']),
            '83313525',
            new \DateTimeImmutable('2026-07-01 08:00:00'),
        );
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
            'poste' => 'Technicien informatique',
            'entreprise' => 'Astek',
            'categorie' => 'sisr',
            'contrat' => 'cdi',
            'pays' => 'France',
            'niveau' => 'Bac+2',
            'niveau_source' => 'annonce',
            'acces_bts' => 'accessible',
            'teletravail' => 'non_precise',
            'date_publication' => '2026-09-12',
            'date_publication_approx' => false,
        ], $overrides);
    }
}
