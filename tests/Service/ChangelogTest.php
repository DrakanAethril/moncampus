<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\ReleaseEntryType;
use App\Service\Changelog;
use PHPUnit\Framework\TestCase;

/**
 * The changelog is a file in the repository, so it ships with the release it describes and needs no
 * command run on the server. That also means it is hand-written, and a hand-written file is exactly
 * where a typo must not take a page down: an unreadable line is dropped, never fatal.
 *
 * Stated on plain arrays - what the YAML parser hands over - rather than on the file itself, so the
 * rule is testable without a fixture on disk (this repo's "type at the boundary" habit).
 */
class ChangelogTest extends TestCase
{
    /** @return array<array-key, mixed> */
    private function data(): array
    {
        return ['releases' => [
            ['version' => '2026.08.1', 'date' => '2026-08-10', 'summary' => 'La rubrique d\'aide.', 'entries' => [
                ['type' => 'nouveaute', 'title' => 'Aide en ligne', 'detail' => 'Articles, questions fréquentes, glossaire.'],
                ['type' => 'interne', 'title' => 'PHPStan niveau 5'],
            ]],
            ['version' => '2026.07.2', 'date' => '2026-07-30', 'summary' => 'Progression pédagogique.', 'entries' => [
                ['type' => 'fix', 'title' => 'Session perdue en production'],
            ]],
        ]];
    }

    public function testReadsReleasesNewestFirst(): void
    {
        $releases = Changelog::parse($this->data());

        self::assertSame(['2026.08.1', '2026.07.2'], array_map(static fn ($r): string => $r->version, $releases));
        self::assertSame('2026-08-10', $releases[0]->date->format('Y-m-d'));
        self::assertSame("La rubrique d'aide.", $releases[0]->summary);
    }

    public function testSortsByDateEvenWhenTheFileDoesNot(): void
    {
        $data = $this->data();
        $data['releases'] = array_reverse($data['releases']);

        self::assertSame(['2026.08.1', '2026.07.2'], array_map(static fn ($r): string => $r->version, Changelog::parse($data)));
    }

    public function testTwoReleasesOnTheSameDayAreOrderedByVersion(): void
    {
        // Two deploys in one day is ordinary - the CalVer rank is monthly, not daily. Without an
        // explicit tie-break the order fell back to the order of the file, which is how the Discord
        // announcement once named 2026.08.10 while the page showed 2026.08.11. The comparison is
        // numeric per segment, so 2026.08.9 sorts below 2026.08.11 rather than above it.
        $releases = Changelog::parse(['releases' => [
            ['version' => '2026.08.9', 'date' => '2026-08-10', 'summary' => ''],
            ['version' => '2026.08.11', 'date' => '2026-08-10', 'summary' => ''],
            ['version' => '2026.08.10', 'date' => '2026-08-10', 'summary' => ''],
        ]]);

        self::assertSame(['2026.08.11', '2026.08.10', '2026.08.9'], array_map(static fn ($r): string => $r->version, $releases));
    }

    public function testReadsEntriesAndTheirType(): void
    {
        $entries = Changelog::parse($this->data())[0]->entries;

        self::assertCount(2, $entries);
        self::assertSame(ReleaseEntryType::Feature, $entries[0]->type);
        self::assertSame('Aide en ligne', $entries[0]->title);
        self::assertSame('Articles, questions fréquentes, glossaire.', $entries[0]->detail);
        self::assertNull($entries[1]->detail);
    }

    public function testSeparatesWhatTheStaffSeesFromWhatIsPurelyTechnical(): void
    {
        $release = Changelog::parse($this->data())[0];

        self::assertSame(['Aide en ligne'], array_map(static fn ($e): string => $e->title, $release->productEntries()));
        self::assertSame(['PHPStan niveau 5'], array_map(static fn ($e): string => $e->title, $release->internalEntries()));
    }

    public function testOrdersEntriesByTypeRatherThanByHowTheyWereWritten(): void
    {
        $release = Changelog::parse(['releases' => [
            ['version' => '1', 'date' => '2026-08-10', 'summary' => '', 'entries' => [
                ['type' => 'fix', 'title' => 'C'],
                ['type' => 'nouveaute', 'title' => 'A'],
                ['type' => 'modification', 'title' => 'B'],
            ]],
        ]])[0];

        self::assertSame(['A', 'B', 'C'], array_map(static fn ($e): string => $e->title, $release->productEntries()));
    }

    public function testAnUnknownTypeFallsBackToOtherInsteadOfBreakingThePage(): void
    {
        $release = Changelog::parse(['releases' => [
            ['version' => '1', 'date' => '2026-08-10', 'summary' => '', 'entries' => [['type' => 'refonte', 'title' => 'X']]],
        ]])[0];

        self::assertSame(ReleaseEntryType::Other, $release->entries[0]->type);
    }

    public function testDropsWhatItCannotRead(): void
    {
        $releases = Changelog::parse(['releases' => [
            ['version' => '2026.08.1', 'date' => '2026-08-10', 'summary' => 'ok', 'entries' => [
                ['title' => 'sans type'],
                ['type' => 'fix'],
                'une chaîne au lieu d\'une entrée',
                ['type' => 'fix', 'title' => 'gardée'],
            ]],
            ['version' => '', 'date' => '2026-08-09', 'summary' => 'sans version'],
            ['version' => '2026.08.0', 'date' => 'pas une date', 'summary' => 'date illisible'],
        ]]);

        self::assertCount(1, $releases);
        self::assertSame(['sans type', 'gardée'], array_map(static fn ($e): string => $e->title, $releases[0]->entries));
        self::assertSame(ReleaseEntryType::Other, $releases[0]->entries[0]->type);
    }

    public function testCountsEntriesByTypeAcrossEveryRelease(): void
    {
        // Feeds the filter pills on /changelog. Internal entries are counted like the others: the
        // pill is how a reader reaches them, and a pill that lies about its own total is worse than
        // no pill.
        $counts = Changelog::entryCounts(Changelog::parse($this->data()));

        self::assertSame(3, $counts['total']);
        self::assertSame(1, $counts[ReleaseEntryType::Feature->value]);
        self::assertSame(1, $counts[ReleaseEntryType::Internal->value]);
        self::assertSame(1, $counts[ReleaseEntryType::Fix->value]);
        self::assertSame(0, $counts[ReleaseEntryType::Change->value]);
    }

    public function testEveryTypeIsCountedEvenWhenAbsent(): void
    {
        // The template renders no pill for a zero, but it must be able to ask without guarding.
        $counts = Changelog::entryCounts([]);

        self::assertSame(0, $counts['total']);
        foreach (ReleaseEntryType::cases() as $type) {
            self::assertSame(0, $counts[$type->value], $type->value);
        }
    }

    public function testAnEmptyOrShapelessFileGivesNoReleases(): void
    {
        self::assertSame([], Changelog::parse([]));
        self::assertSame([], Changelog::parse(['releases' => 'pas une liste']));
    }
}
