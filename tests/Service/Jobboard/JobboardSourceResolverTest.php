<?php

declare(strict_types=1);

namespace App\Tests\Service\Jobboard;

use App\Entity\JobboardSource;
use App\Enum\JobboardLearningKind;
use App\Repository\JobboardSourceRepository;
use App\Service\Jobboard\JobboardSourceResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The rule that replaced a closed list, and it is one sentence: **the URL is the truth, the declared
 * name is a hint**.
 *
 * What is tested here is mostly what *no longer happens*. Every case below used to end in an offer
 * refused with `unknown_source` or `url_domain_mismatch` - and a refused offer is a lost offer,
 * since nothing on this side keeps what it turned away.
 */
class JobboardSourceResolverTest extends TestCase
{
    public function testTheHostDecidesWhateverTheNameSays(): void
    {
        $source = $this->forUrl($this->resolver(), 'meteojob', 'https://www.hellowork.com/fr-fr/emplois/1.html');

        $this->assertSame('hellowork', $source->getSlug());
    }

    /** A batch labelled with a typo used to be refused offer by offer. It now files where it belongs. */
    public function testATypoInTheNameCannotForkASite(): void
    {
        $this->assertSame('hellowork', $this->forUrl($this->resolver(), 'hellowrk', 'https://www.hellowork.com/x')->getSlug());
    }

    public function testASubdomainBelongsToItsDomain(): void
    {
        $source = $this->forUrl($this->resolver(), '', 'https://candidat.francetravail.fr/offres/recherche/detail/212WTTG');

        $this->assertSame('francetravail', $source->getSlug());
    }

    public function testAnUnknownSiteIsCreatedFromItsRegistrableDomain(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist');

        $source = $this->forUrl($this->resolver($entityManager), 'Welcome to the Jungle', 'https://fr.welcometothejungle.com/jobs/1');

        $this->assertSame('welcometothejungle', $source->getSlug());
        $this->assertSame('Welcome to the Jungle', $source->getLabel());
        $this->assertSame(['welcometothejungle.com'], $source->getDomains());
        $this->assertTrue($source->isDiscoveredByAgent());
    }

    /**
     * The dry run of the import screen. It must announce exactly what the real pass will do and
     * write nothing, so a site it meets for the first time is described and never persisted.
     */
    public function testAPreviewCreatesNothing(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');

        $source = $this->resolver($entityManager)->preview('Indeed', 'fr.indeed.com');

        $this->assertSame('indeed', $source->getSlug());
    }

    /**
     * A site that moves. The name is what is left to recognise it by, and attaching the new domain
     * is what makes the *next* offer resolve without asking anybody.
     */
    public function testAKnownSiteMetOnANewDomainAdoptsIt(): void
    {
        $resolver = $this->resolver();
        $source = $this->forUrl($resolver, 'HelloWork', 'https://www.hellowork.fr/emplois/1.html');

        $this->assertSame('hellowork', $source->getSlug());
        $this->assertSame(['hellowork.com', 'hellowork.fr'], $source->getDomains());
    }

    /** A cursor carries no URL; the first offer that does is what gives its site a domain. */
    public function testASiteFirstMetThroughACursorIsCompletedByItsFirstOffer(): void
    {
        $resolver = $this->resolver();
        $created = $resolver->byName('Jobijoba', create: true);

        $this->assertInstanceOf(JobboardSource::class, $created);
        $this->assertSame([], $created->getDomains());

        $again = $this->forUrl($resolver, 'Jobijoba', 'https://www.jobijoba.com/fr/annonces/1');

        $this->assertSame($created, $again);
        $this->assertSame(['jobijoba.com'], $again->getDomains());
    }

    /**
     * The counterweight to never refusing: what the resolution decides on its own is written down.
     * Both gestures, and only those two - a host already known decides nothing new.
     */
    public function testWhatTheResolutionDecidesIsJournalled(): void
    {
        $resolver = $this->resolver();

        $this->forUrl($resolver, 'meteojob', 'https://www.hellowork.com/x');
        $this->forUrl($resolver, 'HelloWork', 'https://www.hellowork.fr/y');
        $this->forUrl($resolver, 'Welcome to the Jungle', 'https://fr.welcometothejungle.com/z');

        $learned = $resolver->takeLearned();

        $this->assertCount(2, $learned);
        $this->assertSame(JobboardLearningKind::DomainAttached, $learned[0]->kind);
        $this->assertSame('hellowork.fr', $learned[0]->domain);
        $this->assertSame('HelloWork', $learned[0]->declared);
        $this->assertSame(JobboardLearningKind::SourceCreated, $learned[1]->kind);
        $this->assertSame('welcometothejungle.com', $learned[1]->domain);
    }

    /**
     * A dry run never attaches anything, so it meets the same unknown host on every line. Announcing
     * it forty times would describe a pass that will happen once.
     */
    public function testAPreviewAnnouncesEachGestureOnce(): void
    {
        $resolver = $this->resolver();

        $resolver->preview('HelloWork', 'www.hellowork.fr');
        $resolver->preview('HelloWork', 'jobs.hellowork.fr');

        $this->assertCount(1, $resolver->takeLearned());
    }

    /** Draining, not reading: two deposits in one process must not inherit each other's gestures. */
    public function testTheJournalIsEmptiedAsItIsRead(): void
    {
        $resolver = $this->resolver();
        $this->forUrl($resolver, 'Indeed', 'https://fr.indeed.com/1');

        $this->assertCount(1, $resolver->takeLearned());
        $this->assertSame([], $resolver->takeLearned());
    }

    /**
     * A site created by name alone comes from a cursor write, which belongs to no deposit and
     * carries no domain. The sources screen already says the veille brought it.
     */
    public function testASiteCreatedWithoutAUrlIsNotJournalled(): void
    {
        $resolver = $this->resolver();
        $resolver->byName('Jobijoba', create: true);

        $this->assertSame([], $resolver->takeLearned());
    }

    /** Closing an offer for a site nothing ever deposited closes nothing: there is nothing to create. */
    public function testAClosingCallNeverCreatesASite(): void
    {
        $this->assertNull($this->resolver()->byName('Jobijoba'));
    }

    public function testANameThatIsNothingButPunctuationIsRefused(): void
    {
        $this->assertNull($this->resolver()->byName('« — »', create: true));
    }

    /**
     * `co.uk` and friends: two labels would make every British site one source. The list is a
     * heuristic and is allowed to be - an administrator corrects a domain in two clicks - but it
     * must not collapse a whole suffix.
     */
    public function testATwoLevelSuffixIsNotSwallowedWhole(): void
    {
        $source = $this->forUrl($this->resolver(), 'Reed', 'https://www.reed.co.uk/jobs/1');

        $this->assertSame(['reed.co.uk'], $source->getDomains());
    }

    /** Two offers of one new site inside a batch are one source, before any flush could find it. */
    public function testANewSiteIsCreatedOnlyOnce(): void
    {
        $resolver = $this->resolver();

        $this->assertSame(
            $this->forUrl($resolver, 'Indeed', 'https://fr.indeed.com/viewjob?jk=1'),
            $this->forUrl($resolver, 'Indeed', 'https://fr.indeed.com/viewjob?jk=2'),
        );
    }

    /**
     * Only reachable from the screen: through the ingestion, a name that normalises onto an
     * existing slug *is* that site. An administrator declaring a second « Hello-Work » by hand is
     * making a different decision, and the slug is what has to stay unique.
     */
    public function testASlugAlreadyTakenGetsASuffix(): void
    {
        $source = $this->resolver()->declareSite('Hello-Work', ['hello-work.example.org']);

        $this->assertSame('hellowork2', $source->getSlug());
    }

    /**
     * FrankenPHP serves this application in worker mode: without reset(), the second request would
     * answer with the first one's table, and a site added meanwhile would stay invisible for as
     * long as the worker lives.
     */
    public function testResetForgetsTheTable(): void
    {
        $repository = $this->createMock(JobboardSourceRepository::class);
        $repository->expects($this->exactly(2))->method('findAllOrdered')->willReturn([]);

        $resolver = new JobboardSourceResolver($repository, $this->createStub(EntityManagerInterface::class));
        $resolver->byName('Indeed');
        $resolver->reset();
        $resolver->byName('Indeed');
    }

    public function testWhatIsNotALinkHasNoHost(): void
    {
        $this->assertNull(JobboardSourceResolver::hostOf("voir sur le site de l'entreprise"));
        $this->assertNull(JobboardSourceResolver::hostOf('javascript:alert(1)'));
        $this->assertNull(JobboardSourceResolver::hostOf('ftp://example.com/x'));
        $this->assertSame('example.com', JobboardSourceResolver::hostOf('https://WWW.Example.com./x'));
    }

    /** The pairing the parser makes: a URL is read for its host, and the host is what resolves. */
    private function forUrl(JobboardSourceResolver $resolver, string $declared, string $url): JobboardSource
    {
        $host = JobboardSourceResolver::hostOf($url);
        $this->assertNotNull($host);

        return $resolver->resolve($declared, $host);
    }

    private function resolver(?EntityManagerInterface $entityManager = null): JobboardSourceResolver
    {
        $repository = $this->createStub(JobboardSourceRepository::class);
        $repository->method('findAllOrdered')->willReturn([
            new JobboardSource('hellowork', 'HelloWork', ['hellowork.com']),
            new JobboardSource('francetravail', 'France Travail', ['francetravail.fr', 'pole-emploi.fr']),
            new JobboardSource('meteojob', 'Meteojob', ['meteojob.com']),
        ]);

        return new JobboardSourceResolver($repository, $entityManager ?? $this->createStub(EntityManagerInterface::class));
    }
}
