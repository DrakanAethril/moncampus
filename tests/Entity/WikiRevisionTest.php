<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\WikiRevision;
use PHPUnit\Framework\TestCase;

/**
 * The revision cap, pruned on write.
 *
 * Without it, a wiki used seriously for a year is the biggest table in the schema - one row per
 * save, per page. What the test pins is the off-by-one that would either keep 51 rows for ever or
 * delete the revision somebody is about to restore.
 */
class WikiRevisionTest extends TestCase
{
    public function testNothingIsPrunedWhileTheCapIsNotReached(): void
    {
        self::assertSame([], WikiRevision::excess([], 3));
        self::assertSame([], WikiRevision::excess([9, 8, 7], 3));
    }

    public function testTheOldestBeyondTheCapArePruned(): void
    {
        // Newest first, as the history screen reads them.
        self::assertSame([6], WikiRevision::excess([9, 8, 7, 6], 3));
        self::assertSame([7, 6, 5], WikiRevision::excess([9, 8, 7, 6, 5], 2));
    }

    public function testTheDefaultCapIsTheOneDeclaredOnTheEntity(): void
    {
        // Ten more revisions than the cap allows, so exactly ten come back whatever the constant
        // is set to - the point being that the default argument really is KEEP_PER_NODE.
        $ids = range(WikiRevision::KEEP_PER_NODE + 10, 1);

        self::assertCount(10, WikiRevision::excess($ids));
        self::assertSame(WikiRevision::excess($ids, WikiRevision::KEEP_PER_NODE), WikiRevision::excess($ids));
    }
}
