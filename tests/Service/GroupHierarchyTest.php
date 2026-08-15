<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\GroupHierarchy;
use PHPUnit\Framework\TestCase;

class GroupHierarchyTest extends TestCase
{
    // campus <- sio <- sio-1 / sio-2, plus an unrelated root.
    private const PARENTS = [
        1 => null,  // campus
        2 => 1,     // sio
        3 => 2,     // sio-1
        4 => 2,     // sio-2
        5 => null,  // mco
    ];

    public function testAncestorIdsAreRootFirstAndExcludeTheGroupItself(): void
    {
        self::assertSame([1, 2], (new GroupHierarchy())->ancestorIds(3, self::PARENTS));
    }

    public function testARootHasNoAncestor(): void
    {
        self::assertSame([], (new GroupHierarchy())->ancestorIds(1, self::PARENTS));
    }

    public function testAnUnknownGroupHasNoAncestor(): void
    {
        self::assertSame([], (new GroupHierarchy())->ancestorIds(99, self::PARENTS));
    }

    // A parent left out of the map (deactivated, so absent from an actives-only listing) ends the
    // walk instead of being reported as an ancestor nobody can resolve.
    public function testAParentMissingFromTheMapEndsTheWalk(): void
    {
        self::assertSame([], (new GroupHierarchy())->ancestorIds(3, [3 => 2]));
    }

    public function testDescendantIdsCoverEveryLevel(): void
    {
        self::assertSame([2, 3, 4], (new GroupHierarchy())->descendantIds(1, self::PARENTS));
    }

    public function testALeafHasNoDescendant(): void
    {
        self::assertSame([], (new GroupHierarchy())->descendantIds(3, self::PARENTS));
    }

    public function testBranchIdsStartOnTheGroupItself(): void
    {
        self::assertSame([2, 3, 4], (new GroupHierarchy())->branchIds(2, self::PARENTS));
    }

    public function testAGroupCannotBeItsOwnParent(): void
    {
        self::assertTrue((new GroupHierarchy())->wouldCycle(2, 2, self::PARENTS));
    }

    public function testMovingAGroupUnderItsOwnDescendantCycles(): void
    {
        self::assertTrue((new GroupHierarchy())->wouldCycle(1, 4, self::PARENTS));
    }

    public function testMovingAGroupUnderAnUnrelatedBranchDoesNotCycle(): void
    {
        self::assertFalse((new GroupHierarchy())->wouldCycle(2, 5, self::PARENTS));
    }

    public function testDetachingAGroupNeverCycles(): void
    {
        self::assertFalse((new GroupHierarchy())->wouldCycle(2, null, self::PARENTS));
    }

    // A loop already stored upstream (data predating the check, or written by hand) must be walked
    // out of rather than answered by hanging.
    public function testAPreExistingLoopUpstreamDoesNotHang(): void
    {
        self::assertFalse((new GroupHierarchy())->wouldCycle(1, 3, [2 => 3, 3 => 2]));
    }

    public function testFlattenOrdersEachBranchUnderItsParent(): void
    {
        $rows = [
            ['id' => 1, 'parentId' => null],
            ['id' => 5, 'parentId' => null],
            ['id' => 2, 'parentId' => 1],
            ['id' => 3, 'parentId' => 2],
            ['id' => 4, 'parentId' => 2],
        ];

        self::assertSame([
            ['id' => 1, 'depth' => 0],
            ['id' => 2, 'depth' => 1],
            ['id' => 3, 'depth' => 2],
            ['id' => 4, 'depth' => 2],
            ['id' => 5, 'depth' => 0],
        ], (new GroupHierarchy())->flatten($rows));
    }

    // Listing only the active groups can leave a child whose parent isn't in the list - it shows as
    // a root rather than disappearing from the screen entirely.
    public function testFlattenTreatsAnOrphanAsARoot(): void
    {
        $rows = [
            ['id' => 3, 'parentId' => 2],
            ['id' => 1, 'parentId' => null],
        ];

        self::assertSame([
            ['id' => 3, 'depth' => 0],
            ['id' => 1, 'depth' => 0],
        ], (new GroupHierarchy())->flatten($rows));
    }

    // Same reason as wouldCycle()'s walk-out: a stored loop is unreachable from any root, and
    // dropping those rows would make the groups vanish from the screen with no error saying why.
    public function testFlattenStillListsGroupsCaughtInALoop(): void
    {
        $rows = [
            ['id' => 1, 'parentId' => null],
            ['id' => 2, 'parentId' => 3],
            ['id' => 3, 'parentId' => 2],
        ];

        self::assertSame([
            ['id' => 1, 'depth' => 0],
            ['id' => 2, 'depth' => 0],
            ['id' => 3, 'depth' => 1],
        ], (new GroupHierarchy())->flatten($rows));
    }
}
