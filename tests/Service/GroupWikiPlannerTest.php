<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\GroupWikiPlanner;
use PHPUnit\Framework\TestCase;

/**
 * The two rules that turn a saved set of groups into a list of wikis, pinned here because both are
 * silent when they break: an extra empty wiki nobody can reach, or a numbering that stops matching
 * what the group-creation screen shows.
 */
class GroupWikiPlannerTest extends TestCase
{
    public function testOneWikiPerGroup(): void
    {
        $plan = (new GroupWikiPlanner())->plan([[1, 2], [3, 4, 5]], 'TP réseau', 'Groupe %n%');

        self::assertCount(2, $plan);
        self::assertSame('TP réseau — Groupe 1', $plan[0]['title']);
        self::assertSame([1, 2], $plan[0]['memberIds']);
        self::assertSame('TP réseau — Groupe 2', $plan[1]['title']);
        self::assertSame([3, 4, 5], $plan[1]['memberIds']);
    }

    public function testAnEmptyGroupGetsNoWiki(): void
    {
        $plan = (new GroupWikiPlanner())->plan([[1, 2], [], [3]], 'TP', 'Groupe %n%');

        self::assertCount(2, $plan);
    }

    public function testNumberingFollowsThePositionInTheSetNotTheRankAmongTheWikis(): void
    {
        $plan = (new GroupWikiPlanner())->plan([[1], [], [3]], 'TP', 'Groupe %n%');

        // The third group stays "Groupe 3" although it is the second wiki created - that is the
        // number the teacher is looking at on the group-creation screen.
        self::assertSame('TP — Groupe 1', $plan[0]['title']);
        self::assertSame('TP — Groupe 3', $plan[1]['title']);
    }

    public function testAnEmptyPrefixLeavesTheGroupTitleAlone(): void
    {
        $plan = (new GroupWikiPlanner())->plan([[1]], '   ', 'Groupe %n%');

        self::assertSame('Groupe 1', $plan[0]['title']);
    }

    public function testAStudentListedTwiceIsOneMember(): void
    {
        $plan = (new GroupWikiPlanner())->plan([[7, 7, 8]], 'TP', 'Groupe %n%');

        self::assertSame([7, 8], $plan[0]['memberIds']);
    }
}
