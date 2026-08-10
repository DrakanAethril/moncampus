<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\ReleaseEntryType;
use App\Service\ChangelogStats;
use App\Service\Release;
use App\Service\ReleaseEntry;
use PHPUnit\Framework\TestCase;

/**
 * The few things the changelog can say about itself.
 *
 * They are on the page to be read for pleasure, which is exactly why they must be computed and not
 * written: a figure nobody can check is decoration, and decoration that claims to be a measurement
 * is worse than none. Each one below is derived from the release list alone.
 */
class ChangelogStatsTest extends TestCase
{
    /** @param list<array{string, string, list<ReleaseEntryType>}> $rows version, date, entry types */
    private function releases(array $rows): array
    {
        return array_map(
            static fn (array $row): Release => new Release(
                $row[0],
                new \DateTimeImmutable($row[1]),
                '',
                array_map(static fn (ReleaseEntryType $t): ReleaseEntry => new ReleaseEntry($t, 'x'), $row[2]),
            ),
            $rows,
        );
    }

    public function testFindsTheBusiestRelease(): void
    {
        $stats = (new ChangelogStats())->of($this->releases([
            ['2026.08.2', '2026-08-02', [ReleaseEntryType::Fix]],
            ['2026.08.1', '2026-08-01', [ReleaseEntryType::Feature, ReleaseEntryType::Fix, ReleaseEntryType::Change]],
        ]));

        self::assertSame('2026.08.1', $stats['busiest']['version']);
        self::assertSame(3, $stats['busiest']['count']);
    }

    public function testCountsTheLongestRunOfConsecutiveDays(): void
    {
        // 5, 6, 7 August then a gap then 10 August: the longest run is three days, not four releases.
        $stats = (new ChangelogStats())->of($this->releases([
            ['d', '2026-08-10', [ReleaseEntryType::Fix]],
            ['c', '2026-08-07', [ReleaseEntryType::Fix]],
            ['b', '2026-08-06', [ReleaseEntryType::Fix]],
            ['a', '2026-08-05', [ReleaseEntryType::Fix]],
        ]));

        self::assertSame(3, $stats['longestStreak']);
    }

    public function testTwoReleasesOnTheSameDayDoNotLengthenTheStreak(): void
    {
        $stats = (new ChangelogStats())->of($this->releases([
            ['b', '2026-08-05', [ReleaseEntryType::Fix]],
            ['a', '2026-08-05', [ReleaseEntryType::Fix]],
        ]));

        self::assertSame(1, $stats['longestStreak']);
    }

    public function testNamesTheMostFrequentWeekday(): void
    {
        // Two Mondays against one Thursday.
        $stats = (new ChangelogStats())->of($this->releases([
            ['c', '2026-08-13', [ReleaseEntryType::Fix]], // jeudi
            ['b', '2026-08-10', [ReleaseEntryType::Fix]], // lundi
            ['a', '2026-08-03', [ReleaseEntryType::Fix]], // lundi
        ]));

        self::assertSame(1, $stats['weekday']);
        self::assertSame(2, $stats['weekdayCount']);
    }

    public function testGivesTheNewToFixedRatio(): void
    {
        $stats = (new ChangelogStats())->of($this->releases([
            ['a', '2026-08-01', [ReleaseEntryType::Feature, ReleaseEntryType::Feature, ReleaseEntryType::Feature, ReleaseEntryType::Fix]],
        ]));

        self::assertSame(3.0, $stats['newPerFix']);
    }

    public function testTheRatioIsNullWithoutASingleFix(): void
    {
        // Not "infinite", not zero: a project that has never fixed anything says nothing here.
        $stats = (new ChangelogStats())->of($this->releases([['a', '2026-08-01', [ReleaseEntryType::Feature]]]));

        self::assertNull($stats['newPerFix']);
    }

    public function testAnEmptyChangelogAnswersEmptyRatherThanFailing(): void
    {
        $stats = (new ChangelogStats())->of([]);

        self::assertNull($stats['busiest']);
        self::assertSame(0, $stats['longestStreak']);
        self::assertNull($stats['weekday']);
        self::assertNull($stats['newPerFix']);
    }
}
