<?php

declare(strict_types=1);

namespace App\Tests\Service\SchoolYearImport;

use App\Service\SchoolYearImport\SchoolYearDateShift;
use PHPUnit\Framework\TestCase;

/**
 * R4: a date of another year is moved by a whole number of weeks - so it keeps its day of the week -
 * then brought back inside the target year when it falls out of it.
 */
class SchoolYearDateShiftTest extends TestCase
{
    private static function day(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date);
    }

    private static function shift(string $sourceStart, string $targetStart, string $targetEnd): SchoolYearDateShift
    {
        return SchoolYearDateShift::between(self::day($sourceStart), self::day($targetStart), self::day($targetEnd));
    }

    public function testWeeksForwardRoundToTheNearestWholeWeek(): void
    {
        // 365 days = 52 weeks and 1 day.
        self::assertSame(52, SchoolYearDateShift::weeks(self::day('2026-09-01'), self::day('2027-09-01')));
        // 4 days rounds up, 3 days rounds down.
        self::assertSame(1, SchoolYearDateShift::weeks(self::day('2026-09-01'), self::day('2026-09-05')));
        self::assertSame(1, SchoolYearDateShift::weeks(self::day('2026-09-01'), self::day('2026-09-11')));
        self::assertSame(2, SchoolYearDateShift::weeks(self::day('2026-09-01'), self::day('2026-09-12')));
        self::assertSame(0, SchoolYearDateShift::weeks(self::day('2026-09-01'), self::day('2026-09-04')));
    }

    public function testWeeksBackwardAreNegative(): void
    {
        self::assertSame(-52, SchoolYearDateShift::weeks(self::day('2027-09-01'), self::day('2026-09-01')));
    }

    public function testWeeksIgnoreTheTimeOfDay(): void
    {
        self::assertSame(52, SchoolYearDateShift::weeks(new \DateTimeImmutable('2026-09-01 23:30'), new \DateTimeImmutable('2027-09-01 00:10')));
    }

    public function testShiftKeepsTheDayOfTheWeek(): void
    {
        $shift = self::shift('2026-09-01', '2027-09-01', '2028-07-07');

        $moved = $shift->shift(self::day('2026-09-07'));

        self::assertSame('2027-09-06', $moved->format('Y-m-d'));
        self::assertSame('Monday', $moved->format('l'));
        self::assertSame(52, $shift->weeks);
    }

    public function testShiftBackwardFromAMoreRecentYear(): void
    {
        $shift = self::shift('2027-09-01', '2026-09-01', '2027-07-03');

        self::assertSame('2026-09-07', $shift->shift(self::day('2027-09-06'))->format('Y-m-d'));
    }

    public function testShiftKeepsTheTimeOfDay(): void
    {
        $shift = self::shift('2026-09-01', '2027-09-01', '2028-07-07');

        self::assertSame('2027-05-12 18:00', $shift->shift(new \DateTimeImmutable('2026-05-13 18:00'))->format('Y-m-d H:i'));
    }

    public function testClampBringsADateBackToTheNearestBound(): void
    {
        $shift = self::shift('2026-09-01', '2027-09-01', '2028-07-07');

        self::assertSame('2027-09-01', $shift->clamp(self::day('2027-08-30'))->format('Y-m-d'));
        self::assertSame('2028-07-07', $shift->clamp(self::day('2028-07-10'))->format('Y-m-d'));
        self::assertSame('2028-01-10', $shift->clamp(self::day('2028-01-10'))->format('Y-m-d'));
    }

    public function testClampComparesDaysAndKeepsTheTime(): void
    {
        $shift = self::shift('2026-09-01', '2027-09-01', '2028-07-07');

        // The last day of the year at 23:59 is inside it.
        self::assertSame('2028-07-07 23:59', $shift->clamp(new \DateTimeImmutable('2028-07-07 23:59'))->format('Y-m-d H:i'));
        self::assertSame('2028-07-07 23:59', $shift->clamp(new \DateTimeImmutable('2028-07-12 23:59'))->format('Y-m-d H:i'));
    }

    public function testMoveSaysWhenADateWasBroughtBack(): void
    {
        $shift = self::shift('2026-09-01', '2027-09-01', '2028-07-07');

        $inside = $shift->move(self::day('2027-01-25'));
        self::assertSame('2028-01-24', $inside->date->format('Y-m-d'));
        self::assertFalse($inside->clamped);

        $past = $shift->move(self::day('2027-07-10'));
        self::assertSame('2028-07-07', $past->date->format('Y-m-d'));
        self::assertTrue($past->clamped);
    }

    public function testRangeWithOneBoundOutsideIsClampedOnThatSide(): void
    {
        $shift = self::shift('2026-09-01', '2027-09-01', '2028-07-07');

        $range = $shift->moveRange(self::day('2027-01-25'), self::day('2027-07-10'));

        self::assertSame('2028-01-24', $range->start->format('Y-m-d'));
        self::assertSame('2028-07-07', $range->end->format('Y-m-d'));
        self::assertTrue($range->clamped);
        self::assertFalse($range->outside);
    }

    public function testRangeEntirelyOutsideTheYearLandsOnTheBoundAndIsFlagged(): void
    {
        $shift = self::shift('2026-09-01', '2027-09-01', '2028-07-07');

        $range = $shift->moveRange(self::day('2027-07-12'), self::day('2027-07-30'));

        self::assertSame('2028-07-07', $range->start->format('Y-m-d'));
        self::assertSame('2028-07-07', $range->end->format('Y-m-d'));
        self::assertTrue($range->clamped);
        self::assertTrue($range->outside);
    }

    public function testRangeEntirelyBeforeTheYearIsOutsideToo(): void
    {
        $shift = self::shift('2026-09-01', '2027-09-01', '2028-07-07');

        $range = $shift->moveRange(self::day('2026-08-01'), self::day('2026-08-20'));

        self::assertSame('2027-09-01', $range->start->format('Y-m-d'));
        self::assertSame('2027-09-01', $range->end->format('Y-m-d'));
        self::assertTrue($range->outside);
    }

    public function testRangeInsideTheYearIsNeitherClampedNorOutside(): void
    {
        $shift = self::shift('2026-09-01', '2027-09-01', '2028-07-07');

        $range = $shift->moveRange(self::day('2026-09-07'), self::day('2026-09-18'));

        self::assertSame('2027-09-06', $range->start->format('Y-m-d'));
        self::assertSame('2027-09-17', $range->end->format('Y-m-d'));
        self::assertFalse($range->clamped);
        self::assertFalse($range->outside);
    }
}
