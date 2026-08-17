<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\DeletedObject;
use App\Service\ObjectStore;
use PHPUnit\Framework\TestCase;

/**
 * The rule that decides when bytes actually go (design/validated/object-deletion.md).
 *
 * Small, and worth having: `origin` is what separates a teacher's deleted course material - thirty
 * days, restorable from the corbeille - from an object nobody ever meant to keep, and getting that
 * backwards means either storing accidents for a month or purging somebody's file overnight.
 */
class ObjectStoreRetentionTest extends TestCase
{
    public function testAnOrdinaryDeletionKeepsItsBytesForThirtyDays(): void
    {
        self::assertSame(30, ObjectStore::retentionDaysFor('file-library'));
        self::assertSame(30, ObjectStore::retentionDaysFor('lesson-log'));
        // An origin nobody declared still gets the platform window rather than none: a key whose
        // prefix is unknown is a file all the same.
        self::assertSame(30, ObjectStore::retentionDaysFor('something-nobody-declared'));
    }

    public function testTheShortLivedOriginsAreTheOnesNobodyAskedToKeep(): void
    {
        // A staged upload the form never claimed, and an import batch abandoned at step 2. Neither
        // appears in any corbeille, so a thirty-day window would only be storage.
        self::assertSame(1, ObjectStore::retentionDaysFor('staged'));
        self::assertSame(1, ObjectStore::retentionDaysFor('import'));
    }

    public function testARowStartsUnpurgedAndRecordsWhyItFailed(): void
    {
        $row = new DeletedObject('dev/file-library/12/abc.pdf', 'file-library');

        self::assertNull($row->getPurgedAt());
        self::assertSame(0, $row->getAttempts());

        $row->recordFailure(str_repeat('Access Denied. ', 40));

        self::assertSame(1, $row->getAttempts());
        // The column is 255 and an AWS message is routinely longer - truncated rather than refused,
        // because losing the reason is worse than losing its tail.
        self::assertNotNull($row->getLastError());
        self::assertSame(255, mb_strlen($row->getLastError()));
        self::assertNull($row->getPurgedAt(), 'A failed purge must leave the row unpurged, or the bytes leak silently.');
    }
}
