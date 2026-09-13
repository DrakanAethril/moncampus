<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Command\PurgePlatformActivityCommand;
use App\Entity\JobboardOffer;
use App\Entity\Track;
use App\Entity\User;
use App\Repository\JobboardOfferRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The retention `app:purge-platform-activity` applies to the jobboard, and the one trap it must not
 * fall into.
 *
 * **The publication date is what is read, and the first-seen date when there is none.** Half the
 * sources publish no date at all, and a threshold read against a column that is null for them would
 * either spare them for ever or take them out on the first run, depending on the engine.
 *
 * And purging is not closing: an offer that left its site keeps its row, because how long it stayed
 * online is an information. Only age decides here.
 *
 * The horizon is **two years**, and the last test pins it: a year is a single hiring season, and a
 * board read as the history of what a filière's market asked for needs the season before to compare
 * against.
 */
class JobboardRetentionTest extends FunctionalTestCase
{
    private ?User $author = null;

    public function testAnAdvertOlderThanTheThresholdIsDeletedAndARecentOneIsNot(): void
    {
        $track = $this->track();
        $old = $this->offer($track, 'old', published: '-30 months');
        $recent = $this->offer($track, 'recent', published: '-14 months');

        $this->assertSame(1, $this->purge());

        $this->assertNull($this->offers()->find((int) $old->getId()));
        $this->assertNotNull($this->offers()->find((int) $recent->getId()));
    }

    public function testAnUndatedOfferIsJudgedOnTheDayItWasFirstSeen(): void
    {
        $track = $this->track();
        $stale = $this->offer($track, 'stale', published: null, firstSeen: '-30 months');
        $fresh = $this->offer($track, 'fresh', published: null, firstSeen: '-3 days');

        $this->assertSame(1, $this->purge());

        $this->assertNull($this->offers()->find((int) $stale->getId()));
        // The one the trap would have taken: no publication date, seen last week.
        $this->assertNotNull($this->offers()->find((int) $fresh->getId()));
    }

    public function testAClosedButRecentOfferSurvives(): void
    {
        $track = $this->track();
        $offer = $this->offer($track, 'closed', published: '-14 months');
        $offer->close(new \DateTimeImmutable());
        $this->manager()->flush();

        $this->assertSame(0, $this->purge());
        $this->assertNotNull($this->offers()->find((int) $offer->getId()));
    }

    /** The duration the command applies by default, and the one this test purges against. */
    public function testTheDefaultHorizonIsTwoYears(): void
    {
        $command = static::getContainer()->get(PurgePlatformActivityCommand::class);
        $default = $command->getDefinition()->getOption('jobboard-months')->getDefault();

        $this->assertIsScalar($default);
        $this->assertSame(24, (int) $default);
    }

    private function purge(): int
    {
        $deleted = $this->offers()->deletePublishedBefore(new \DateTimeImmutable('-24 months'));
        $this->manager()->clear();

        return $deleted;
    }

    private function offers(): JobboardOfferRepository
    {
        return static::getContainer()->get(JobboardOfferRepository::class);
    }

    private function track(): Track
    {
        $track = $this->createProgram([], [], $this->author())->getCohort()?->getTrack();
        $this->assertInstanceOf(Track::class, $track);

        return $track;
    }

    private function offer(Track $track, string $ref, ?string $published, string $firstSeen = '-30 months'): JobboardOffer
    {
        $offer = new JobboardOffer($track, $this->jobboardSource(), $ref, new \DateTimeImmutable($firstSeen));
        $offer->setUrl('https://www.hellowork.com/fr-fr/emplois/'.$ref.'.html')->setPosition('Technicien')->setCompany('Astek');

        if (null !== $published) {
            $offer->setPublication(new \DateTimeImmutable($published), false);
        }

        $this->manager()->persist($offer);
        $this->manager()->flush();

        return $offer;
    }

    private function author(): User
    {
        return $this->author ??= $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'jobboard.retention.author');
    }

    private function manager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
