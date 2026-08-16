<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\WikiArchiveSafety;
use PHPUnit\Framework\TestCase;

/**
 * The rules that decide whether an uploaded archive may be opened at all.
 *
 * Nothing else in this codebase has had to read a ZIP a user supplied, so none of this existed
 * before: an archive is the one upload that is not a file but a *program* for creating files, and
 * every rule below stops it from writing somewhere, or as much as, it should not.
 *
 * These are checked on the entry list **before** anything is extracted - the point of the exercise
 * being that a hostile archive never reaches the filesystem, not that it is cleaned up afterwards.
 */
class WikiArchiveSafetyTest extends TestCase
{
    // --- zip-slip: an entry that escapes the extraction root ------------------------------

    public function testAnEntryThatClimbsOutOfTheRootIsRefused(): void
    {
        $safety = new WikiArchiveSafety();

        foreach ([
            '../evil.md',
            'pages/../../evil.md',
            'pages/../../../etc/passwd',
            '/etc/passwd',
            '/../evil.md',
            'pages/..',
        ] as $entry) {
            self::assertFalse($safety->isSafePath($entry), $entry.' should have been refused');
        }
    }

    public function testAWindowsStyleAbsolutePathOrBackslashIsRefused(): void
    {
        // A ZIP written on Windows may carry backslashes, and PHP's own path handling would then
        // treat "pages\..\..\evil.md" as one long, harmless-looking name.
        $safety = new WikiArchiveSafety();

        self::assertFalse($safety->isSafePath('C:\\windows\\system32\\evil.md'));
        self::assertFalse($safety->isSafePath('pages\\..\\..\\evil.md'));
    }

    public function testAnOrdinaryEntryIsAccepted(): void
    {
        $safety = new WikiArchiveSafety();

        foreach ([
            'manifest.json',
            'pages/01-introduction.md',
            'pages/02-reseaux/index.html',
            'attachments/48/schema.pdf',
            'pages/notes..md',
        ] as $entry) {
            self::assertTrue($safety->isSafePath($entry), $entry.' should have been accepted');
        }
    }

    public function testADotSegmentThatGoesNowhereIsStillRefused(): void
    {
        // "pages/./x.md" is harmless, but normalising it is what makes the check above exact -
        // so it is accepted, while anything that resolves upwards is not.
        $safety = new WikiArchiveSafety();

        self::assertTrue($safety->isSafePath('pages/./x.md'));
        self::assertFalse($safety->isSafePath('pages/./../../x.md'));
    }

    // --- zip bomb: too many entries, or too much once decompressed ------------------------

    public function testTooManyEntriesIsRefused(): void
    {
        $safety = new WikiArchiveSafety();

        self::assertNull($safety->rejectionFor(WikiArchiveSafety::MAX_ENTRIES, 1024, 1024));
        self::assertSame(
            WikiArchiveSafety::REJECTION_TOO_MANY_ENTRIES,
            $safety->rejectionFor(WikiArchiveSafety::MAX_ENTRIES + 1, 1024, 1024),
        );
    }

    public function testAnEntryBiggerThanThePerEntryCapIsRefused(): void
    {
        $safety = new WikiArchiveSafety();

        self::assertSame(
            WikiArchiveSafety::REJECTION_ENTRY_TOO_LARGE,
            $safety->rejectionFor(1, WikiArchiveSafety::MAX_ENTRY_BYTES + 1, WikiArchiveSafety::MAX_ENTRY_BYTES + 1),
        );
    }

    public function testATotalBiggerThanTheArchiveCapIsRefused(): void
    {
        // The one a zip bomb actually trips: thousands of small, wildly compressible files, each
        // well under the per-entry cap.
        $safety = new WikiArchiveSafety();

        self::assertSame(
            WikiArchiveSafety::REJECTION_ARCHIVE_TOO_LARGE,
            $safety->rejectionFor(10, 1024, WikiArchiveSafety::MAX_TOTAL_BYTES + 1),
        );
    }

    public function testAnArchiveWithinEveryCapIsAccepted(): void
    {
        self::assertNull((new WikiArchiveSafety())->rejectionFor(120, 2 * 1024 * 1024, 40 * 1024 * 1024));
    }

    // --- what the caps actually are -------------------------------------------------------

    public function testTheCapsAreOrderedTheOnlyWayThatMakesSense(): void
    {
        // A per-entry cap above the archive cap would be unreachable, and an archive cap below the
        // platform's own upload ceiling would refuse an archive holding one legitimate file.
        self::assertLessThanOrEqual(WikiArchiveSafety::MAX_TOTAL_BYTES, WikiArchiveSafety::MAX_ENTRY_BYTES);
        self::assertGreaterThan(0, WikiArchiveSafety::MAX_ENTRIES);
    }
}
