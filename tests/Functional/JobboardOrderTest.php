<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\JobboardOffer;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\JobboardContract;
use App\Enum\JobboardSource;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The board is read in the order the veille brought the offers back, not in the order the adverts
 * say they were published.
 *
 * The two orders disagree for a reason that is structural rather than accidental: the collecting
 * agents do not all pass every day. An agent that runs once a week brings back adverts published
 * over that whole week, and sorted on the publication date its harvest arrives already buried under
 * a daily agent's - which is exactly the day it is worth looking at.
 *
 * So this test builds the case where the two orders disagree and pins the one the screen uses.
 */
class JobboardOrderTest extends FunctionalTestCase
{
    private ?User $author = null;

    public function testTheListIsOrderedOnTheDayTheOfferWasSpottedNotOnItsPublicationDate(): void
    {
        $admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'jobboard.order.admin');
        $track = $this->createProgram([], [], $this->author())->getCohort()?->getTrack();
        $this->assertInstanceOf(Track::class, $track);

        // The week-old advert of an agent that has just passed, and the fresh advert of one that
        // passed three days ago. Publication puts them in one order, repérage in the other.
        $this->offer($track, 'Ref-1', 'Technicien repéré ce matin', published: '-8 days', firstSeen: '-1 hour');
        $this->offer($track, 'Ref-2', 'Technicien repéré avant-hier', published: '-1 day', firstSeen: '-3 days');

        $this->client->loginUser($admin);
        $html = $this->client->request('GET', '/jobboard')->html();

        $this->assertLessThan(
            strpos($html, 'Technicien repéré avant-hier'),
            strpos($html, 'Technicien repéré ce matin'),
            "L'offre repérée le plus récemment passe devant, quelle que soit sa date de publication.",
        );
    }

    /** An offer that never carried a publication date is not pushed to the far end of the list. */
    public function testAnUndatedOfferKeepsItsPlaceAmongTheOthers(): void
    {
        $admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'jobboard.order.admin');
        $track = $this->createProgram([], [], $this->author())->getCohort()?->getTrack();
        $this->assertInstanceOf(Track::class, $track);

        $this->offer($track, 'Ref-1', 'Technicien sans date de publication', published: null, firstSeen: '-1 hour');
        $this->offer($track, 'Ref-2', 'Technicien publié hier', published: '-1 day', firstSeen: '-3 days');

        $this->client->loginUser($admin);
        $html = $this->client->request('GET', '/jobboard')->html();

        $this->assertLessThan(
            strpos($html, 'Technicien publié hier'),
            strpos($html, 'Technicien sans date de publication'),
            'Une offre sans date de publication se range sur son repérage, pas en fin de liste.',
        );
    }

    private function author(): User
    {
        return $this->author ??= $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'jobboard.order.fixture.author');
    }

    private function offer(
        Track $track,
        string $reference,
        string $position,
        ?string $published,
        string $firstSeen,
    ): JobboardOffer {
        $offer = new JobboardOffer(
            $track,
            JobboardSource::Hellowork,
            $reference,
            new \DateTimeImmutable($firstSeen),
        );
        $offer
            ->setUrl('https://www.hellowork.com/fr-fr/emplois/83313525.html')
            ->setPosition($position)
            ->setCompany('Astek')
            ->setContract(JobboardContract::Cdi)
            ->setLevel('Bac+2')
            ->setPublication(null === $published ? null : new \DateTimeImmutable($published), false)
        ;

        $this->manager()->persist($offer);
        $this->manager()->flush();

        return $offer;
    }

    private function manager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
