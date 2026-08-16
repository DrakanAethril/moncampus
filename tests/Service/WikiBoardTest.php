<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\WikiBoard;
use PHPUnit\Framework\TestCase;

/**
 * How "Mes wikis" arranges itself.
 *
 * Flat, the screen becomes an unreadable pile after two school years, so it is grouped by class -
 * and the grouping key is derived, never stored: the assigned Program(s), or for a wiki assigned to
 * named students, the program those students belong to. The two edge cases are named rather than
 * dropped into a silent catch-all, which is exactly what these tests pin.
 */
class WikiBoardTest extends TestCase
{
    public function testAWikiWithNoStudentInItHeadsThePageUnderItsOwnGroup(): void
    {
        $groups = (new WikiBoard())->group([
            ['id' => 1, 'programIds' => [], 'hasStudentAudience' => false],
        ]);

        self::assertCount(1, $groups);
        self::assertSame(WikiBoard::GROUP_COLLEAGUES, $groups[0]['kind']);
        self::assertNull($groups[0]['programId']);
        self::assertSame([1], $groups[0]['wikiIds']);
    }

    public function testAWikiAssignedToOneClassGroupsUnderIt(): void
    {
        $groups = (new WikiBoard())->group([
            ['id' => 1, 'programIds' => [7], 'hasStudentAudience' => true],
            ['id' => 2, 'programIds' => [7], 'hasStudentAudience' => true],
        ]);

        self::assertCount(1, $groups);
        self::assertSame(WikiBoard::GROUP_PROGRAM, $groups[0]['kind']);
        self::assertSame(7, $groups[0]['programId']);
        self::assertSame([1, 2], $groups[0]['wikiIds']);
    }

    public function testMembersSpreadAcrossSeveralClassesGetTheirOwnNamedGroup(): void
    {
        $groups = (new WikiBoard())->group([
            ['id' => 1, 'programIds' => [7, 8], 'hasStudentAudience' => true],
        ]);

        self::assertCount(1, $groups);
        self::assertSame(WikiBoard::GROUP_SEVERAL, $groups[0]['kind']);
        self::assertNull($groups[0]['programId']);
    }

    public function testAStudentAudienceThatResolvesToNoClassIsNamedRatherThanMislabelled(): void
    {
        // A named student who belongs to no program at all: calling this "Plusieurs classes" would
        // be a lie, and folding it into "Entre collègues" would hide a student. It gets its own
        // heading instead.
        $groups = (new WikiBoard())->group([
            ['id' => 1, 'programIds' => [], 'hasStudentAudience' => true],
        ]);

        self::assertCount(1, $groups);
        self::assertSame(WikiBoard::GROUP_OTHER, $groups[0]['kind']);
    }

    public function testColleaguesComeFirstAndTheNamedGroupsCloseTheList(): void
    {
        $groups = (new WikiBoard())->group([
            ['id' => 1, 'programIds' => [], 'hasStudentAudience' => true],
            ['id' => 2, 'programIds' => [7, 8], 'hasStudentAudience' => true],
            ['id' => 3, 'programIds' => [7], 'hasStudentAudience' => true],
            ['id' => 4, 'programIds' => [], 'hasStudentAudience' => false],
        ]);

        self::assertSame(
            [WikiBoard::GROUP_COLLEAGUES, WikiBoard::GROUP_PROGRAM, WikiBoard::GROUP_SEVERAL, WikiBoard::GROUP_OTHER],
            array_column($groups, 'kind'),
        );
    }

    public function testClassGroupsKeepTheOrderTheirProgramsWereGivenIn(): void
    {
        // The caller hands the programs in nav order (school year desc, then name); the board must
        // not re-sort them by id and undo that.
        $groups = (new WikiBoard())->group(
            [
                ['id' => 1, 'programIds' => [7], 'hasStudentAudience' => true],
                ['id' => 2, 'programIds' => [3], 'hasStudentAudience' => true],
            ],
            [3, 7],
        );

        self::assertSame([3, 7], array_column($groups, 'programId'));
    }

    public function testAnEmptyBoardHasNoGroupAtAll(): void
    {
        self::assertSame([], (new WikiBoard())->group([]));
    }
}
