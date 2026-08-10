<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Cadence;
use PHPUnit\Framework\TestCase;

/**
 * How often something happens, said the way a reader would say it.
 *
 * The whole point is the switch: above one a day, "40 commits per day" reads naturally and
 * "1 commit every 0.02 days" is nonsense; below one a day it is exactly the other way round. Pinned
 * because the boundary is where a rate stops being readable, not where it stops being computable.
 */
class CadenceTest extends TestCase
{
    public function testSaysPerDayWhenItHappensMoreThanOnceADay(): void
    {
        // 1449 / 36 = 40.25, rounded to the unit: past ten a day, the decimal is noise.
        $cadence = (new Cadence())->describe(1449, 36);

        self::assertTrue($cadence['perDay']);
        self::assertSame(40.0, $cadence['rate']);
    }

    public function testSaysOneEveryNDaysWhenItIsRarerThanDaily(): void
    {
        $cadence = (new Cadence())->describe(33, 36);

        self::assertFalse($cadence['perDay']);
        self::assertSame(1.1, $cadence['rate']);
    }

    public function testExactlyOneADayCountsAsPerDay(): void
    {
        // The boundary belongs to the readable side: "1 per day" beats "1 every 1 day".
        $cadence = (new Cadence())->describe(36, 36);

        self::assertTrue($cadence['perDay']);
        self::assertSame(1.0, $cadence['rate']);
    }

    public function testARateAboveTenLosesItsDecimal(): void
    {
        // "40 commits per day" - the tenth of a commit is noise at that scale.
        self::assertSame(40.0, (new Cadence())->describe(1200, 30)['rate']);
        self::assertSame(9.5, (new Cadence())->describe(285, 30)['rate']);
    }

    public function testNoDayAndNoCountGiveNothingRatherThanADivisionByZero(): void
    {
        self::assertNull((new Cadence())->describe(10, 0));
        self::assertNull((new Cadence())->describe(0, 10));
        self::assertNull((new Cadence())->describe(10, -3));
    }
}
