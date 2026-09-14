<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\JobboardOffer;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\JobboardContract;
use App\Service\Jobboard\JobboardOfferFinder;
use App\Service\Jobboard\OfferFilters;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The one rule of this feature that is a security rule: a reader sees the offers of their own
 * filières and of no others, **and the narrowing is a WHERE clause**.
 *
 * Which is why these tests do not stop at checking that a row is absent from the HTML. They also
 * ask the finder itself, because a screen that merely refrains from drawing something is one
 * refactoring away from drawing it - and since the row became a link to the advert, there is no
 * per-offer URL left to try instead.
 */
class JobboardPerimeterTest extends FunctionalTestCase
{
    private ?User $author = null;

    public function testAStudentReadsOnlyTheOffersOfTheirOwnFiliere(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'jobboard.student');
        $mine = $this->createProgram([$student], [], $this->author())->getCohort()?->getTrack();
        $theirs = $this->createProgram([], [], $this->author())->getCohort()?->getTrack();

        $this->assertInstanceOf(Track::class, $mine);
        $this->assertInstanceOf(Track::class, $theirs);

        $this->offer($mine, 'Technicien de ma filière');
        $this->offer($theirs, "Technicien d'une autre filière");

        $this->client->loginUser($student);
        $crawler = $this->client->request('GET', '/jobboard');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Technicien de ma filière', $crawler->html());
        $this->assertStringNotContainsString("Technicien d'une autre filière", $crawler->html());
    }

    /**
     * The narrowing asked of the finder rather than of the HTML: the offer of another filière is not
     * merely undrawn, it is not in the rows the query brings back.
     */
    public function testTheFinderItselfNeverReturnsAnotherFilieresOffer(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'jobboard.student');
        $this->createProgram([$student], [], $this->author());
        $theirs = $this->createProgram([], [], $this->author())->getCohort()?->getTrack();
        $this->assertInstanceOf(Track::class, $theirs);

        $this->offer($theirs, "Technicien d'une autre filière");

        $page = static::getContainer()->get(JobboardOfferFinder::class)
            ->page($student, OfferFilters::fromRequest(new Request()), null, false);

        $this->assertSame([], $page->offers);
    }

    /**
     * Ticking a filière one is not in narrows, it never substitutes: the answer to a forged query
     * string is an empty list, not another filière's offers.
     */
    public function testTickingAForeignFiliereReturnsNothing(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'jobboard.student');
        $this->createProgram([$student], [], $this->author());
        $theirs = $this->createProgram([], [], $this->author())->getCohort()?->getTrack();
        $this->assertInstanceOf(Track::class, $theirs);

        $this->offer($theirs, "Technicien d'une autre filière");

        $this->client->loginUser($student);
        $crawler = $this->client->request('GET', '/jobboard?filiere='.$theirs->getId());

        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString("Technicien d'une autre filière", $crawler->html());
    }

    /** An administrator reads every filière - that is the only perimeter that is not a membership. */
    public function testAnAdministratorReadsEveryFiliere(): void
    {
        $admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'jobboard.admin');
        $first = $this->createProgram([], [], $this->author())->getCohort()?->getTrack();
        $this->assertInstanceOf(Track::class, $first);

        $this->offer($first, 'Technicien vu par un administrateur');

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/jobboard');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Technicien vu par un administrateur', $crawler->html());
    }

    /** The source is an administrator's filter, and it is absent from the markup for anybody else. */
    public function testTheSourceIsAbsentFromAStudentsScreen(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'jobboard.student');
        $track = $this->createProgram([$student], [], $this->author())->getCohort()?->getTrack();
        $this->assertInstanceOf(Track::class, $track);

        $this->offer($track, 'Technicien informatique');

        $this->client->loginUser($student);
        $crawler = $this->client->request('GET', '/jobboard');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Technicien informatique', $crawler->html());
        $this->assertStringNotContainsString('HelloWork', $crawler->html());
        $this->assertStringNotContainsString('REF-SECRET-42', $crawler->html());
    }

    /**
     * An administrator gets the site as a filter and nothing more: no row, for anybody, prints which
     * site an offer came from, and the reference is now nowhere on this screen at all.
     */
    public function testAnAdministratorGetsTheSourceAsAFilterAndNotOnTheRow(): void
    {
        $admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'jobboard.admin');
        $track = $this->createProgram([], [], $this->author())->getCohort()?->getTrack();
        $this->assertInstanceOf(Track::class, $track);

        $this->offer($track, 'Technicien informatique');

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/jobboard');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('.cm-jb-row'));
        $this->assertStringNotContainsString('HelloWork', $crawler->filter('.cm-jb-row')->html());
        $this->assertStringNotContainsString('REF-SECRET-42', $crawler->html());
        $this->assertGreaterThan(0, $crawler->filter('.cm-jb-menu .cm-jb-option:contains("HelloWork")')->count());
    }

    /** A closed offer is excluded from every reading, administrator included. */
    public function testAClosedOfferIsReachableByNobody(): void
    {
        $admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'jobboard.admin');
        $track = $this->createProgram([], [], $this->author())->getCohort()?->getTrack();
        $this->assertInstanceOf(Track::class, $track);

        $offer = $this->offer($track, 'Offre retirée du site');
        $offer->close(new \DateTimeImmutable());
        $this->manager()->flush();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/jobboard');
        $this->assertStringNotContainsString('Offre retirée du site', $crawler->html());
    }

    /**
     * One author for every structure a test builds: createProgram() mints « fixture.author » when
     * it is given none, and that username is unique - two calls in one test collide.
     */
    private function author(): User
    {
        return $this->author ??= $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'jobboard.fixture.author');
    }

    private function offer(Track $track, string $position): JobboardOffer
    {
        // The reference is deliberately not a substring of the URL: the row itself links to the
        // advert, so a reference hidden inside that href would make the test pass for the wrong
        // reason.
        $offer = new JobboardOffer($track, $this->jobboardSource(), 'REF-SECRET-42', new \DateTimeImmutable());
        $offer
            ->setUrl('https://www.hellowork.com/fr-fr/emplois/83313525.html')
            ->setPosition($position)
            ->setCompany('Astek')
            ->setContract(JobboardContract::Cdi)
            ->setLevel('Bac+2')
            ->setPublication(new \DateTimeImmutable('2026-09-12'), false)
        ;

        $this->manager()->persist($offer);
        $this->manager()->flush();

        return $offer;
    }

    /**
     * The same manager the base class opened its transaction on - taken from the container rather
     * than kept as a field, so this test writes on the connection the requests read from.
     */
    private function manager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
