<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\JobboardOffer;
use App\Entity\JobboardSource;
use App\Entity\Track;
use App\Entity\User;
use App\Repository\JobboardOfferRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Configuration > Jobboard > Sources », the one gesture on that screen that is a decision rather
 * than a correction.
 *
 * Three properties are pinned, and they are the three halves of what makes it usable:
 *
 * - **it erases**, because a site nobody wants must leave the board and not merely stop growing;
 * - **it keeps the row and its domains**, which is what lets the ingestion recognise - and drop -
 *   everything that arrives afterwards;
 * - **it is reversible, and it does not undo the erasure.** Coming back off the list opens the door
 *   again; the offers that went through it are gone, and only the next pass brings back what is
 *   still online.
 */
class JobboardSourceBlacklistTest extends FunctionalTestCase
{
    private ?User $admin = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client->loginUser($this->admin());
        // Opens the session csrfToken() borrows.
        $this->client->request('GET', '/settings/jobboard/sources');
    }

    public function testBlacklistingASiteErasesItsOffersAndKeepsTheRow(): void
    {
        $source = $this->jobboardSource();
        $track = $this->track();
        $this->offer($track, $source, '1');
        $this->offer($track, $source, '2');

        $this->post($source, 'blacklist');

        $this->assertResponseRedirects('/settings/jobboard/sources');
        $this->assertSame(0, $this->offers()->count(['source' => $source->getId()]));

        $stored = $this->reread($source);
        $this->assertTrue($stored->isBlacklisted());
        // The domains stay: they are what resolves the offers that will keep arriving, and dropping
        // them would turn the next deposit into the discovery of a brand new site.
        $this->assertSame(['hellowork.com'], $stored->getDomains());
    }

    /** Only that site: the blacklist is a row, not a mode the screen enters. */
    public function testTheOffersOfAnotherSiteAreLeftAlone(): void
    {
        $source = $this->jobboardSource();
        $other = $this->jobboardSource('indeed', 'Indeed', 'indeed.fr');
        $track = $this->track();
        $this->offer($track, $source, '1');
        $kept = $this->offer($track, $other, '2');

        $this->post($source, 'blacklist');

        $this->assertNotNull($this->offers()->find((int) $kept->getId()));
    }

    public function testComingOffTheListDoesNotBringTheOffersBack(): void
    {
        $source = $this->jobboardSource();
        $this->offer($this->track(), $source, '1');

        $this->post($source, 'blacklist');
        $this->post($source, 'allow');

        $this->assertFalse($this->reread($source)->isBlacklisted());
        $this->assertSame(0, $this->offers()->count(['source' => $source->getId()]));
    }

    public function testTheGestureIsRefusedWithoutItsToken(): void
    {
        $source = $this->jobboardSource();

        $this->client->request('POST', '/settings/jobboard/sources/'.$source->getId().'/blacklist', ['_token' => 'stale']);

        $this->assertResponseStatusCodeSame(403);
        $this->assertFalse($this->reread($source)->isBlacklisted());
    }

    private function post(JobboardSource $source, string $action): void
    {
        $this->client->request('POST', '/settings/jobboard/sources/'.$source->getId().'/'.$action, [
            '_token' => $this->csrfToken('jobboard_source_blacklist'),
        ]);
    }

    private function reread(JobboardSource $source): JobboardSource
    {
        $stored = $this->manager()->getRepository(JobboardSource::class)->find((int) $source->getId());
        $this->assertInstanceOf(JobboardSource::class, $stored);

        return $stored;
    }

    private function offer(Track $track, JobboardSource $source, string $ref): JobboardOffer
    {
        $offer = new JobboardOffer($track, $source, $ref, new \DateTimeImmutable('-2 days'));
        $offer->setUrl('https://www.hellowork.com/fr-fr/emplois/'.$ref.'.html')->setPosition('Technicien')->setCompany('Astek');

        $this->manager()->persist($offer);
        $this->manager()->flush();

        return $offer;
    }

    private function track(): Track
    {
        $track = $this->createProgram([], [], $this->admin())->getCohort()?->getTrack();
        $this->assertInstanceOf(Track::class, $track);

        return $track;
    }

    private function offers(): JobboardOfferRepository
    {
        return static::getContainer()->get(JobboardOfferRepository::class);
    }

    private function admin(): User
    {
        return $this->admin ??= $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'jobboard.blacklist.admin');
    }

    private function manager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
