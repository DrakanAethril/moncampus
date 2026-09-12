<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\JobboardOffer;
use App\Entity\Section;
use App\Entity\User;
use App\Enum\JobboardContract;
use App\Enum\JobboardSource;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one rule of this feature that is a security rule: a reader sees the offers of their own
 * filières and of no others, **and the narrowing is a WHERE clause**.
 *
 * Which is why these tests do not check that a row is absent from the HTML. They check that the
 * offer of another filière cannot be reached at all - not by the list, not by its own URL - because
 * a screen that merely refrains from drawing something is one refactoring away from drawing it.
 */
class JobboardPerimeterTest extends FunctionalTestCase
{
    private ?User $author = null;

    public function testAStudentReadsOnlyTheOffersOfTheirOwnFiliere(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'jobboard.student');
        $mine = $this->createProgram([$student], [], $this->author())->getCohort()?->getTrack()?->getSection();
        $theirs = $this->createProgram([], [], $this->author())->getCohort()?->getTrack()?->getSection();

        $this->assertInstanceOf(Section::class, $mine);
        $this->assertInstanceOf(Section::class, $theirs);

        $this->offer($mine, 'Technicien de ma filière');
        $this->offer($theirs, "Technicien d'une autre filière");

        $this->client->loginUser($student);
        $crawler = $this->client->request('GET', '/jobboard');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Technicien de ma filière', $crawler->html());
        $this->assertStringNotContainsString("Technicien d'une autre filière", $crawler->html());
    }

    public function testTheDetailOfAnotherFiliereAnswersNotFound(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'jobboard.student');
        $this->createProgram([$student], [], $this->author());
        $theirs = $this->createProgram([], [], $this->author())->getCohort()?->getTrack()?->getSection();
        $this->assertInstanceOf(Section::class, $theirs);

        $offer = $this->offer($theirs, "Technicien d'une autre filière");

        $this->client->loginUser($student);
        $this->client->request('GET', '/jobboard/offers/'.$offer->getId());

        // 404 and not 403: "it is not yours" and "it does not exist" must be the same answer.
        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * Ticking a filière one is not in narrows, it never substitutes: the answer to a forged query
     * string is an empty list, not another filière's offers.
     */
    public function testTickingAForeignFiliereReturnsNothing(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'jobboard.student');
        $this->createProgram([$student], [], $this->author());
        $theirs = $this->createProgram([], [], $this->author())->getCohort()?->getTrack()?->getSection();
        $this->assertInstanceOf(Section::class, $theirs);

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
        $first = $this->createProgram([], [], $this->author())->getCohort()?->getTrack()?->getSection();
        $this->assertInstanceOf(Section::class, $first);

        $this->offer($first, 'Technicien vu par un administrateur');

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/jobboard');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Technicien vu par un administrateur', $crawler->html());
    }

    /** The source is an administrator's line, and it is absent from the markup for anybody else. */
    public function testTheSourceIsAbsentFromAStudentsDetailPanel(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'jobboard.student');
        $section = $this->createProgram([$student], [], $this->author())->getCohort()?->getTrack()?->getSection();
        $this->assertInstanceOf(Section::class, $section);

        $offer = $this->offer($section, 'Technicien informatique');

        $this->client->loginUser($student);
        $crawler = $this->client->request('GET', '/jobboard/offers/'.$offer->getId());

        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('HelloWork', $crawler->html());
        $this->assertStringNotContainsString('REF-SECRET-42', $crawler->html());
    }

    public function testAnAdministratorReadsTheSourceAndTheReference(): void
    {
        $admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'jobboard.admin');
        $section = $this->createProgram([], [], $this->author())->getCohort()?->getTrack()?->getSection();
        $this->assertInstanceOf(Section::class, $section);

        $offer = $this->offer($section, 'Technicien informatique');

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/jobboard/offers/'.$offer->getId());

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('HelloWork', $crawler->html());
        $this->assertStringContainsString('REF-SECRET-42', $crawler->html());
    }

    /** A closed offer is excluded from every reading, administrator included. */
    public function testAClosedOfferIsReachableByNobody(): void
    {
        $admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'jobboard.admin');
        $section = $this->createProgram([], [], $this->author())->getCohort()?->getTrack()?->getSection();
        $this->assertInstanceOf(Section::class, $section);

        $offer = $this->offer($section, 'Offre retirée du site');
        $offer->close(new \DateTimeImmutable());
        $this->manager()->flush();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/jobboard');
        $this->assertStringNotContainsString('Offre retirée du site', $crawler->html());

        $this->client->request('GET', '/jobboard/offers/'.$offer->getId());
        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * One author for every structure a test builds: createProgram() mints « fixture.author » when
     * it is given none, and that username is unique - two calls in one test collide.
     */
    private function author(): User
    {
        return $this->author ??= $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'jobboard.fixture.author');
    }

    private function offer(Section $section, string $position): JobboardOffer
    {
        // The reference is deliberately not a substring of the URL: the URL is shown to every
        // reader by « Voir l'offre », so a reference hidden inside it would make the test pass for
        // the wrong reason.
        $offer = new JobboardOffer($section, JobboardSource::Hellowork, 'REF-SECRET-42', new \DateTimeImmutable());
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
