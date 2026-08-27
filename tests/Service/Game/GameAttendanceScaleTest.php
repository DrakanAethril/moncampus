<?php

declare(strict_types=1);

namespace App\Tests\Service\Game;

use App\Enum\AttendanceState;
use App\Service\Game\GameAttendanceScale;
use PHPUnit\Framework\TestCase;

/**
 * The relevé's arithmetic (§5.1), including the two rules that decide whether the game is fair to
 * an apprentice: out of scope leaves the denominator, and out of scope does not break a streak.
 */
class GameAttendanceScaleTest extends TestCase
{
    private GameAttendanceScale $scale;

    protected function setUp(): void
    {
        $this->scale = new GameAttendanceScale();
    }

    /** @param list<AttendanceState> $states */
    private function scaleOf(array $states, int $weeks = 1): array
    {
        return $this->scale->points(
            array_map(static fn (AttendanceState $state): array => ['state' => $state, 'weeks' => $weeks], $states),
            30,
            10,
            40,
        );
    }

    public function testACleanUnitPaysItsWeeksAndNothingElseOnItsOwn(): void
    {
        $scale = $this->scaleOf([AttendanceState::Clean]);

        self::assertSame(30, $scale[0]['points']);
        self::assertSame(0, $scale[0]['streak'], 'the first unit of a run has nothing to be consecutive to');
        self::assertSame(30, $scale[0]['possible']);
    }

    public function testAMonthlyUnitIsWorthAsManyWeeksAsItCovers(): void
    {
        $scale = $this->scaleOf([AttendanceState::Clean], 4);

        self::assertSame(120, $scale[0]['points']);
        self::assertSame(120, $scale[0]['possible']);
    }

    public function testANotCleanUnitPaysNothingAndStaysInTheDenominator(): void
    {
        $scale = $this->scaleOf([AttendanceState::NotClean]);

        self::assertSame(0, $scale[0]['points']);
        self::assertSame(30, $scale[0]['possible'], 'it counted: the student was concerned by that week');
    }

    public function testAnOutOfScopeUnitLeavesTheDenominatorRatherThanCostingPoints(): void
    {
        $scale = $this->scaleOf([AttendanceState::OutOfScope]);

        self::assertSame(0, $scale[0]['points']);
        self::assertSame(0, $scale[0]['possible'], 'the apprentice is not ranked on the weeks they were in the company');
    }

    public function testEachConsecutiveCleanUnitBeyondTheFirstAddsTheStreak(): void
    {
        $scale = $this->scaleOf(array_fill(0, 5, AttendanceState::Clean));

        self::assertSame([0, 10, 10, 10, 10], array_column($scale, 'streak'));
        // The mockup's « 4 semaines nettes d'affilée - la cinquième vaut 30 + 10 ».
        self::assertSame(40, $scale[4]['points']);
    }

    public function testTheStreakBonusStopsAtItsCeilingInsideOneRun(): void
    {
        $scale = $this->scaleOf(array_fill(0, 8, AttendanceState::Clean));

        self::assertSame(40, array_sum(array_column($scale, 'streak')));
        self::assertSame([0, 10, 10, 10, 10, 0, 0, 0], array_column($scale, 'streak'));
    }

    public function testANotCleanUnitResetsTheRun(): void
    {
        $scale = $this->scaleOf([
            AttendanceState::Clean,
            AttendanceState::Clean,
            AttendanceState::NotClean,
            AttendanceState::Clean,
            AttendanceState::Clean,
        ]);

        self::assertSame([0, 10, 0, 0, 10], array_column($scale, 'streak'));
    }

    public function testAnOutOfScopeUnitDoesNotBreakARun(): void
    {
        // A fortnight in the company between two clean weeks: the run stands, because being in the
        // company is not a fault.
        $scale = $this->scaleOf([
            AttendanceState::Clean,
            AttendanceState::OutOfScope,
            AttendanceState::Clean,
        ]);

        self::assertSame([0, 0, 10], array_column($scale, 'streak'));
        self::assertSame(30, $scale[2]['points'] - $scale[2]['streak']);
    }

    public function testThePossibleIsTheUnitsThatConcernedTheStudent(): void
    {
        $scale = $this->scaleOf([
            AttendanceState::Clean,
            AttendanceState::Clean,
            AttendanceState::NotClean,
            AttendanceState::OutOfScope,
            AttendanceState::Clean,
        ]);

        self::assertSame(120, $this->scale->possible($scale), 'four units counted out of five');

        // The streak never enters the possible: it is a bonus laid on top, and
        // GameScoreCalculator caps the rate at 1 rather than letting a run read as 111 %.
        self::assertSame(10, array_sum(array_column($scale, 'streak')));
        self::assertSame(100, array_sum(array_column($scale, 'points')));
    }

    public function testAStudentWithNothingButOutOfScopeUnitsHasNoDenominatorAtAll(): void
    {
        $scale = $this->scaleOf(array_fill(0, 4, AttendanceState::OutOfScope));

        self::assertSame(0, $this->scale->possible($scale), 'the family leaves the index rather than reading 0 %');
    }
}
