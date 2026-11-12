<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\EvaluationPeriod;
use App\Entity\Period;
use App\Entity\PeriodGroup;
use App\Entity\Program;
use App\Entity\SchoolYear;
use PHPUnit\Framework\TestCase;

/**
 * « À relire » goes away when a date shifted by the school-year import is changed - and only then: a
 * form submitting the same day again is not a change.
 */
class ShiftedDatesTraitTest extends TestCase
{
    private static function period(): Period
    {
        $year = new SchoolYear(new \DateTimeImmutable('2027-09-01'), new \DateTimeImmutable('2028-07-07'));

        return new Period('P1', new \DateTimeImmutable('2027-09-06'), new \DateTimeImmutable('2027-09-17'), null, new PeriodGroup('Cal', $year));
    }

    public function testTheSameDateAgainKeepsTheMark(): void
    {
        $period = self::period();
        $period->markShifted(new \DateTimeImmutable());

        $period->setStartDate(new \DateTimeImmutable('2027-09-06'));

        self::assertTrue($period->isShifted());
    }

    public function testAnotherDateClearsTheMark(): void
    {
        $period = self::period();
        $period->markShifted(new \DateTimeImmutable());

        $period->setEndDate(new \DateTimeImmutable('2027-09-24'));

        self::assertFalse($period->isShifted());
    }

    public function testAnEvaluationPeriodComparesAfterItsOwnNormalisation(): void
    {
        $period = new EvaluationPeriod();
        $period->setEndDate(new \DateTimeImmutable('2028-01-21'));
        $period->markShifted(new \DateTimeImmutable());

        // The form hands the day back at midnight; the entity keeps it at 23:59:59 either way.
        $period->setEndDate(new \DateTimeImmutable('2028-01-21'));
        self::assertTrue($period->isShifted());

        $period->setEndDate(new \DateTimeImmutable('2028-01-28'));
        self::assertFalse($period->isShifted());
    }

    public function testConfirmingClearsTheMark(): void
    {
        $period = self::period();
        $period->markShifted(new \DateTimeImmutable());

        $period->confirmShiftedDates();

        self::assertFalse($period->isShifted());
    }

    public function testAPortfolioDeadlineChangedClearsTheProgramsMark(): void
    {
        $program = (new \ReflectionClass(Program::class))->newInstanceWithoutConstructor();
        $program->setPortfolioE5Deadline(new \DateTimeImmutable('2028-05-12 18:00'));
        $program->markPortfolioDatesShifted(new \DateTimeImmutable());

        $program->setPortfolioE5Deadline(new \DateTimeImmutable('2028-05-12 18:00'));
        self::assertNotNull($program->getPortfolioDatesShiftedAt());

        $program->setPortfolioE5Deadline(new \DateTimeImmutable('2028-05-19 18:00'));
        self::assertNull($program->getPortfolioDatesShiftedAt());
    }
}
