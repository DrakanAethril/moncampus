<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TeachingTeam;
use PHPUnit\Framework\TestCase;

class TeachingTeamTest extends TestCase
{
    public function testTheBookletReadsTheLinesInAlphabeticalOrderOfMatiere(): void
    {
        $entries = [
            self::entry('a', 'Mathématiques', 'Dupont'),
            self::entry('b', 'anglais', 'Smith'),
            self::entry('c', 'Économie', 'Martin'),
            self::entry('d', 'Culture générale', 'Durand'),
        ];

        self::assertSame(
            ['anglais', 'Culture générale', 'Économie', 'Mathématiques'],
            array_column(TeachingTeam::forBooklet($entries, []), 'topic'),
        );
    }

    public function testTwoLinesOnTheSameMatiereAreOrderedByTeacher(): void
    {
        $entries = [
            self::entry('a', 'Anglais', 'Smith'),
            self::entry('b', 'Anglais', 'Brown'),
        ];

        self::assertSame(['Brown', 'Smith'], array_column(TeachingTeam::forBooklet($entries, []), 'teacher'));
    }

    public function testALineWithNoOptionIsForEveryoneAndANarrowedOneOnlyForItsOptions(): void
    {
        $entries = [
            self::entry('a', 'Commun', 'X'),
            self::entry('b', 'Réservé SLAM', 'Y', [1]),
            self::entry('c', 'Réservé SISR', 'Z', [2]),
            self::entry('d', 'SLAM ou SISR', 'W', [1, 2]),
        ];

        self::assertSame(['Commun', 'Réservé SLAM', 'SLAM ou SISR'], array_column(TeachingTeam::forBooklet($entries, [1]), 'topic'));
        // A student with no option at all is shown only what is common to everyone.
        self::assertSame(['Commun'], array_column(TeachingTeam::forBooklet($entries, []), 'topic'));
    }

    public function testAStoredValueIsReadBackDefensively(): void
    {
        $stored = [
            ['id' => 'a', 'topic' => ' Anglais ', 'teacher' => 'Smith', 'optionIds' => ['3', 4, 'x']],
            ['topic' => 'Sans identifiant', 'teacher' => 'Nobody'],
            'not an entry',
            ['id' => 'b', 'topic' => 'Maths'],
        ];

        self::assertSame([
            ['id' => 'a', 'topic' => 'Anglais', 'teacher' => 'Smith', 'optionIds' => [3, 4]],
            ['id' => 'b', 'topic' => 'Maths', 'teacher' => '', 'optionIds' => []],
        ], TeachingTeam::normalize($stored));
    }

    public function testPuttingAnEntryReplacesTheOneWithTheSameIdAndAppendsOtherwise(): void
    {
        $entries = [self::entry('a', 'Anglais', 'Smith'), self::entry('b', 'Maths', 'Dupont')];

        $replaced = TeachingTeam::put($entries, self::entry('a', 'Anglais', 'Brown'));
        self::assertSame(['Brown', 'Dupont'], array_column($replaced, 'teacher'));

        $appended = TeachingTeam::put($entries, self::entry('c', 'Physique', 'Curie'));
        self::assertSame(['a', 'b', 'c'], array_column($appended, 'id'));
    }

    public function testRemovingAnEntryKeepsTheOthers(): void
    {
        $entries = [self::entry('a', 'Anglais', 'Smith'), self::entry('b', 'Maths', 'Dupont')];

        self::assertSame(['b'], array_column(TeachingTeam::remove($entries, 'a'), 'id'));
        self::assertSame(['a', 'b'], array_column(TeachingTeam::remove($entries, 'zzz'), 'id'));
        self::assertNull(TeachingTeam::find($entries, 'zzz'));
        self::assertSame('Dupont', TeachingTeam::find($entries, 'b')['teacher'] ?? null);
    }

    public function testFreshIdsAreShortAndDistinct(): void
    {
        $id = TeachingTeam::newId();

        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $id);
        self::assertNotSame($id, TeachingTeam::newId());
    }

    /**
     * @param list<int> $optionIds
     *
     * @return array{id: string, topic: string, teacher: string, optionIds: list<int>}
     */
    private static function entry(string $id, string $topic, string $teacher, array $optionIds = []): array
    {
        return ['id' => $id, 'topic' => $topic, 'teacher' => $teacher, 'optionIds' => $optionIds];
    }
}
