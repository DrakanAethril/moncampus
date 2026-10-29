<?php

declare(strict_types=1);

namespace App\Tests\SeriousGame\Stats;

use App\SeriousGame\Stats\PlayStats;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic of the statistics (design/validated/serious-games-neko-con.md §6), on primitives:
 * the time played without the pauses of more than ten minutes, the share played on school time,
 * the week of a moment, medians, the first option each student chose, the criteria that fail.
 */
class PlayStatsTest extends TestCase
{
    public function testThePlayTimeLeavesOutPausesOfMoreThanTenMinutes(): void
    {
        $t = strtotime('2026-10-12 10:00:00 Europe/Paris');

        self::assertSame(0, PlayStats::playSeconds([]));
        self::assertSame(0, PlayStats::playSeconds([$t]));
        self::assertSame(300, PlayStats::playSeconds([$t, $t + 120, $t + 300]));
        self::assertSame(300, PlayStats::playSeconds([$t + 300, $t, $t + 120]), 'in any order');
        self::assertSame(300 + 60, PlayStats::playSeconds([$t, $t + 300, $t + 300 + 601, $t + 300 + 661]), 'a pause of 10 min 01 is not played');
        self::assertSame(900, PlayStats::playSeconds([$t, $t + 600, $t + 900]), 'ten minutes exactly still count');
    }

    public function testSchoolTimeIsMondayToFridayFromEightToSix(): void
    {
        $monday = strtotime('2026-10-12 10:00:00 Europe/Paris');
        $evening = strtotime('2026-10-12 20:00:00 Europe/Paris');
        $saturday = strtotime('2026-10-17 10:00:00 Europe/Paris');

        self::assertTrue(PlayStats::isSchoolTime($monday));
        self::assertFalse(PlayStats::isSchoolTime($evening));
        self::assertFalse(PlayStats::isSchoolTime($saturday));
        self::assertFalse(PlayStats::isSchoolTime(strtotime('2026-10-12 07:59:00 Europe/Paris')));
        self::assertFalse(PlayStats::isSchoolTime(strtotime('2026-10-12 18:00:00 Europe/Paris')));

        self::assertSame(['school' => 120, 'outside' => 60], PlayStats::schoolSplit([$monday, $monday + 120, $evening, $evening + 60]));
    }

    public function testAMomentBelongsToItsIsoWeekAndDay(): void
    {
        self::assertSame('2026-W42', PlayStats::week(strtotime('2026-10-12 10:00:00 Europe/Paris')));
        self::assertSame('2026-W42', PlayStats::week(strtotime('2026-10-18 23:00:00 Europe/Paris')));
        self::assertSame('2026-10-18', PlayStats::day(strtotime('2026-10-18 23:30:00 Europe/Paris')), 'the day in Paris, not in UTC');
    }

    public function testTheMedian(): void
    {
        self::assertNull(PlayStats::median([]));
        self::assertSame(3.0, PlayStats::median([5, 1, 3]));
        self::assertSame(2.5, PlayStats::median([4, 1, 2, 3]));
    }

    public function testTheFirstChoiceOfEachStudentIsCounted(): void
    {
        $answers = [
            ['player' => 1, 'input' => '2'],
            ['player' => 1, 'input' => '1'],
            ['player' => 2, 'input' => '1'],
            ['player' => 3, 'input' => '2'],
            ['player' => 4, 'input' => 'x'],
        ];

        self::assertSame([0, 1, 2, 0], PlayStats::firstChoices($answers, 4));
    }

    public function testTheFailingCriteriaAreCountedOneByOne(): void
    {
        self::assertSame(
            ['Le slogan a ses accents' => 2, 'La date est lisible' => 1],
            PlayStats::criteria(['Le slogan a ses accents · La date est lisible', 'Le slogan a ses accents', null, '']),
        );
    }
}
