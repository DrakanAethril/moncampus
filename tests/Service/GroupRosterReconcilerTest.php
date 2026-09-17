<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\GroupRosterReconciler;
use PHPUnit\Framework\TestCase;

/**
 * « Recalculer l'effectif » - what a saved lot becomes once the class it was drawn on has moved.
 * The two halves are deliberately asymmetric, and the tests say so: leaving the class takes a
 * student out of wherever they sat, including a locked group (they are not in the class any more,
 * and no lock makes them so), while joining it only ever fills a group the teacher left open.
 *
 * Placement is smallest-group-first and fully deterministic: this is a repair, not a draw, and a
 * teacher clicking twice must not see two different screens.
 */
class GroupRosterReconcilerTest extends TestCase
{
    private GroupRosterReconciler $reconciler;

    protected function setUp(): void
    {
        $this->reconciler = new GroupRosterReconciler();
    }

    public function testAStudentWhoLeftTheClassIsTakenOutOfTheirGroup(): void
    {
        $result = $this->reconciler->reconcile([[1, 2], [3, 99]], [1, 2, 3], [1, 2, 3]);

        self::assertSame([[1, 2], [3]], $result['groups']);
        self::assertSame([99], $result['removed']);
        self::assertSame([], $result['added']);
    }

    public function testAStudentWhoJoinedSinceIsPlacedInTheSmallestGroup(): void
    {
        $result = $this->reconciler->reconcile([[1, 2, 3], [4]], [1, 2, 3, 4, 5], [1, 2, 3, 4, 5]);

        self::assertSame([[1, 2, 3], [4, 5]], $result['groups']);
        self::assertSame([5], $result['added']);
    }

    public function testEqualGroupsSendTheNewcomerToTheFirstOfThem(): void
    {
        // Deterministic on purpose: the same click twice must give the same screen.
        $result = $this->reconciler->reconcile([[1], [2]], [1, 2, 3], [1, 2, 3]);

        self::assertSame([[1, 3], [2]], $result['groups']);
    }

    public function testSeveralNewcomersSpreadInsteadOfPilingUp(): void
    {
        $result = $this->reconciler->reconcile([[1], [2], [3]], [1, 2, 3, 7, 8, 9], [1, 2, 3, 7, 8, 9]);

        self::assertSame([[1, 7], [2, 8], [3, 9]], $result['groups']);
    }

    public function testDepartureAndArrivalAreSettledInThatOrder(): void
    {
        // The seat freed by the student who left is the smallest group by the time the newcomer is
        // placed - reconciling in the other order would have sent them to group 2 instead.
        $result = $this->reconciler->reconcile([[1, 99], [2, 3]], [1, 2, 3, 5], [1, 2, 3, 5]);

        self::assertSame([[1, 5], [2, 3]], $result['groups']);
    }

    public function testALockedGroupTakesNoNewcomerButStillLosesAStudentWhoLeft(): void
    {
        $result = $this->reconciler->reconcile([[1, 99], [2]], [1, 2, 3], [1, 2, 3], [0]);

        self::assertSame([[1], [2, 3]], $result['groups']);
        self::assertSame([99], $result['removed']);
        self::assertSame([3], $result['added']);
    }

    public function testWithEveryGroupLockedTheNewcomersComeBackUnplaced(): void
    {
        // Nothing is placed against the teacher's locks, and nothing is swallowed either: the
        // screen has to be able to say who is still waiting for a group.
        $result = $this->reconciler->reconcile([[1], [2]], [1, 2, 3], [1, 2, 3], [0, 1]);

        self::assertSame([[1], [2]], $result['groups']);
        self::assertSame([], $result['added']);
        self::assertSame([3], $result['unplaced']);
    }

    public function testAStudentOutsideTheScopeIsNeitherPlacedNorRemoved(): void
    {
        // An absent student, or one outside the Option the panel is filtering on: still in the
        // class, so their seat is left alone, but never handed a new one.
        $result = $this->reconciler->reconcile([[1, 2]], [1, 2, 3], [1]);

        self::assertSame([[1, 2]], $result['groups']);
        self::assertSame([], $result['added']);
        self::assertSame([], $result['removed']);
    }

    public function testAnEmptiedGroupStaysAsAnEmptyGroup(): void
    {
        // The number of groups and their order are the teacher's, not the roster's - a group that
        // lost everybody is still a group they asked for, and dropping it would renumber the rest.
        $result = $this->reconciler->reconcile([[98], [1], [99]], [1], [1]);

        self::assertSame([[], [1], []], $result['groups']);
    }

    public function testAStudentSittingInTwoGroupsKeepsOnlyTheirFirstSeat(): void
    {
        $result = $this->reconciler->reconcile([[1, 2], [2, 3]], [1, 2, 3], [1, 2, 3]);

        self::assertSame([[1, 2], [3]], $result['groups']);
    }

    public function testAnUpToDateLotIsLeftExactlyAsItStands(): void
    {
        $result = $this->reconciler->reconcile([[1, 2], [3, 4]], [1, 2, 3, 4], [1, 2, 3, 4]);

        self::assertSame([[1, 2], [3, 4]], $result['groups']);
        self::assertSame([], $result['added']);
        self::assertSame([], $result['removed']);
        self::assertSame([], $result['unplaced']);
    }
}
