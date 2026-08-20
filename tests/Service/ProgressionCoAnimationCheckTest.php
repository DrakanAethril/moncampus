<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ProgressionCoAnimationCheck;
use PHPUnit\Framework\TestCase;

/**
 * « Groupe non couvert : G2 » - the one gap co-animation leaves in the current model.
 *
 * The situation it catches is asymmetric planning: teacher A lays a séance on their own group's
 * créneau and nothing anywhere says the other group was forgotten. It is a CHECK and not a field
 * (design/validated/co-animation.md): it is derived from placements that move constantly, and a
 * stored column would be wrong the first time a créneau did.
 *
 * The rule is exercised here on primitives - group keys and labels - rather than on entities,
 * because that is all it is: two sets and a difference. What builds those sets from Options and
 * placements is plumbing; what has to be right is which shortfall counts.
 */
class ProgressionCoAnimationCheckTest extends TestCase
{
    public function testAGroupWithNoPlacementIsReported(): void
    {
        self::assertSame(
            ['G2'],
            ProgressionCoAnimationCheck::uncovered(['1' => 'G1', '2' => 'G2'], ['1']),
        );
    }

    public function testBothGroupsCoveredReportsNothing(): void
    {
        self::assertSame(
            [],
            ProgressionCoAnimationCheck::uncovered(['1' => 'G1', '2' => 'G2'], ['1', '2']),
        );
    }

    public function testAWholeClassDeliveryCoversEverybody(): void
    {
        // A créneau naming no Option holds the whole class, so every group received the séance -
        // this is the case that must not raise, and it is why the covered side carries nulls
        // rather than being a plain list of keys.
        self::assertSame(
            [],
            ProgressionCoAnimationCheck::uncovered(['1' => 'G1', '2' => 'G2'], [null]),
        );
    }

    public function testAMatiereThatIsNotSplitReportsNothing(): void
    {
        // No group créneau at all: there is nothing to leave uncovered, and the check must stay
        // silent rather than invent a shortfall out of an empty set.
        self::assertSame([], ProgressionCoAnimationCheck::uncovered([], [null]));
        self::assertSame([], ProgressionCoAnimationCheck::uncovered(['1' => 'G1'], ['1']));
    }

    public function testAnUnplacedSeanceReportsNothing(): void
    {
        // A séance on no créneau at all is already « Non placée », with its own status chip. Adding
        // "groupe non couvert : G1, G2" on top would say the same thing twice and turn a fresh
        // séquence into a wall of orange.
        self::assertSame([], ProgressionCoAnimationCheck::uncovered(['1' => 'G1', '2' => 'G2'], []));
    }

    public function testTheReportIsKeyedOnTheOptionAndNotOnItsLabel(): void
    {
        // Two Options may share a short name; covering one must not silence the other. Same
        // reasoning as the Qualiopi builder's groupKey, which is the id for exactly this reason.
        self::assertSame(
            ['TP'],
            ProgressionCoAnimationCheck::uncovered(['7' => 'TP', '9' => 'TP'], ['7']),
        );
    }

    public function testAPlacementOnAGroupTheMatiereNoLongerOffersIsIgnored(): void
    {
        // The créneaux are the authority on which groups exist. A placement pointing at an Option
        // that has since left the matière's timetable covers nothing, and must not add a group to
        // the expected set either.
        self::assertSame(['G1'], ProgressionCoAnimationCheck::uncovered(['1' => 'G1'], ['42']));
    }
}
