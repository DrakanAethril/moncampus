<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\JobboardOffer;
use App\Entity\Section;
use App\Entity\User;
use App\Enum\JobboardSource;
use App\Repository\JobboardOfferRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The retention `app:purge-platform-activity` applies to the jobboard, and the one trap it must not
 * fall into.
 *
 * **`sort_date` is not the date to read.** An undated offer carries 1000-01-01 there, deliberately,
 * so it lands at the far end of a date-descending list - a threshold read against that column would
 * take out every undated offer on the first run. The date read is the publication date, and when
 * there is none, the day the offer was first seen.
 *
 * And purging is not closing: an offer that left its site keeps its row, because how long it stayed
 * online is an information. Only age decides here.
 */
class JobboardRetentionTest extends FunctionalTestCase
{
    private ?User $author = null;

    public function testAnAdvertOlderThanTheThresholdIsDeletedAndARecentOneIsNot(): void
    {
        $section = $this->section();
        $old = $this->offer($section, 'old', published: '-14 months');
        $recent = $this->offer($section, 'recent', published: '-2 months');

        $this->assertSame(1, $this->purge());

        $this->assertNull($this->offers()->find((int) $old->getId()));
        $this->assertNotNull($this->offers()->find((int) $recent->getId()));
    }

    public function testAnUndatedOfferIsJudgedOnTheDayItWasFirstSeen(): void
    {
        $section = $this->section();
        $stale = $this->offer($section, 'stale', published: null, firstSeen: '-14 months');
        $fresh = $this->offer($section, 'fresh', published: null, firstSeen: '-3 days');

        $this->assertSame(1, $this->purge());

        $this->assertNull($this->offers()->find((int) $stale->getId()));
        // The one the trap would have taken: no publication date, seen last week.
        $this->assertNotNull($this->offers()->find((int) $fresh->getId()));
    }

    public function testAClosedButRecentOfferSurvives(): void
    {
        $section = $this->section();
        $offer = $this->offer($section, 'closed', published: '-2 months');
        $offer->close(new \DateTimeImmutable());
        $this->manager()->flush();

        $this->assertSame(0, $this->purge());
        $this->assertNotNull($this->offers()->find((int) $offer->getId()));
    }

    private function purge(): int
    {
        $deleted = $this->offers()->deletePublishedBefore(new \DateTimeImmutable('-12 months'));
        $this->manager()->clear();

        return $deleted;
    }

    private function offers(): JobboardOfferRepository
    {
        return static::getContainer()->get(JobboardOfferRepository::class);
    }

    private function section(): Section
    {
        $section = $this->createProgram([], [], $this->author())->getCohort()?->getTrack()?->getSection();
        $this->assertInstanceOf(Section::class, $section);

        return $section;
    }

    private function offer(Section $section, string $ref, ?string $published, string $firstSeen = '-14 months'): JobboardOffer
    {
        $offer = new JobboardOffer($section, JobboardSource::Hellowork, $ref, new \DateTimeImmutable($firstSeen));
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
