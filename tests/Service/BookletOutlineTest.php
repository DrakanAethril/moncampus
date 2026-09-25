<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BookletOutline;
use PHPUnit\Framework\TestCase;

/**
 * One outline feeds the printed sommaire and the reader's menu, so they cannot disagree - they used
 * to be two hand-written lists, and a section added to one had to be remembered in the other.
 */
class BookletOutlineTest extends TestCase
{
    public function testWithoutModalitiesChapterOneStopsAtTheTeam(): void
    {
        self::assertSame(
            ['I.', '1.', '2.', '3.', '4.', 'II.', '1.', '2.', 'III.'],
            array_column((new BookletOutline())->entries([], false, []), 'number'),
        );
    }

    public function testTheModalitySectionsFollowTheTeamInChapterOne(): void
    {
        $entries = (new BookletOutline())->entries([
            ['number' => 5, 'label' => 'Modalités', 'anchor' => 'section-i-5'],
            ['number' => 6, 'label' => 'Durée de travail', 'anchor' => 'section-i-6'],
        ], false, []);

        self::assertSame(['section-i-4', 'section-i-5', 'section-i-6', 'section-ii'], array_slice(array_column($entries, 'anchor'), 4, 4));
        self::assertSame(['number' => '5.', 'labelKey' => null, 'label' => 'Modalités', 'anchor' => 'section-i-5', 'level' => 2, 'printed' => true], $entries[5]);
    }

    public function testAnEmploiDuTempsPushesTheExamModalitiesToThree(): void
    {
        $entries = (new BookletOutline())->entries([], true, []);

        self::assertSame(
            ['section-ii-1' => '1.', 'section-ii-2' => '2.', 'section-ii-3' => '3.'],
            array_column(array_slice($entries, 6, 3), 'number', 'anchor'),
        );
        self::assertSame('ufaAlternanceLivretSectionExamLabel', $entries[8]['labelKey']);
    }

    /** The printed sommaire stops at III; only the reader lists the periods, under it. */
    public function testPeriodsAreListedForTheReaderOnly(): void
    {
        $entries = (new BookletOutline())->entries([], false, ['Période 1', 'Période 2']);
        $last = array_slice($entries, -2);

        self::assertSame(['section-period-1', 'section-period-2'], array_column($last, 'anchor'));
        self::assertSame(['Période 1', 'Période 2'], array_column($last, 'label'));
        self::assertSame([false, false], array_column($last, 'printed'));
        self::assertSame(['', ''], array_column($last, 'number'));
    }
}
