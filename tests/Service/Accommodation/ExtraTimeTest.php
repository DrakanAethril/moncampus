<?php

declare(strict_types=1);

namespace App\Tests\Service\Accommodation;

use App\Service\Accommodation\ExtraTime;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic of « ajout de temps », and the one rule it has: the fraction goes to the student.
 *
 * A third more on a 25-second question is 33 s and a third. Rounded down it is 33 s, which is less
 * time than the accommodation says; rounded up it is 34 s. Every calculation here rounds up, and
 * that is the only place the decision lives.
 */
class ExtraTimeTest extends TestCase
{
    public function testTheFractionOfASecondGoesToTheStudent(): void
    {
        // 25 x 1.3333 = 33.3325 -> 34, never 33.
        self::assertSame(34, ExtraTime::apply(25, 33.33));
        // 30 x 1.3333 = 39.999 -> 40, and not 39: a third more on half a minute is forty seconds,
        // which is what anybody would have said out loud.
        self::assertSame(40, ExtraTime::apply(30, 33.33));
    }

    public function testAWholeResultIsNotPushedToTheNextSecond(): void
    {
        // ceil() must not turn an exact 45 into 46 - the rounding is a repair, not a bonus.
        self::assertSame(45, ExtraTime::apply(30, 50.0));
    }

    public function testNoLimitStaysNoLimit(): void
    {
        // A question or a quiz with no time budget does not acquire one by being sat by somebody
        // with extra time.
        self::assertNull(ExtraTime::apply(null, 33.33));
        self::assertNull(ExtraTime::apply(null, 0.0));
    }

    public function testWithoutExtraTimeTheBudgetIsUntouched(): void
    {
        self::assertSame(30, ExtraTime::apply(30, 0.0));
    }

    public function testTheGrantedSecondsAreTheDifferenceAndNothingElse(): void
    {
        self::assertSame(9, ExtraTime::addedSeconds(25, 33.33));
        self::assertSame(0, ExtraTime::addedSeconds(25, 0.0));
        self::assertSame(0, ExtraTime::addedSeconds(null, 33.33));
    }

    public function testAPercentageIsWrittenTheWayItWasTyped(): void
    {
        self::assertSame('33,33 %', ExtraTime::percentLabel(33.33));
        // Typed as "50", it must not come back as « 50,00 % » - that reads as a precision nobody
        // claimed.
        self::assertSame('50 %', ExtraTime::percentLabel(50.0));
        self::assertSame('12,5 %', ExtraTime::percentLabel(12.5));
    }
}
