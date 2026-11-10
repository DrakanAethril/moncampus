<?php

declare(strict_types=1);

namespace App\Tests\Service\SeatingPlan;

use App\Enum\SeatingFurniture;
use App\Enum\SeatingRuleType;
use App\Service\SeatingPlan\SeatingPlanDocument;
use App\Service\SeatingPlan\SeatingRoom;
use App\Service\SeatingPlan\SeatingRule;
use App\Service\SeatingPlan\SeatingRuleChecker;
use App\Service\SeatingPlan\SeatingRuleResult;
use App\Service\SeatingPlan\SeatingStudent;
use App\Service\SeatingPlan\SeatingTable;
use PHPUnit\Framework\TestCase;

/**
 * What each rule means, on a small room: three ranks of two double tables, an aisle between them.
 *
 *     rank 0   t1(1,2)(2,2)   t2(4,2)(5,2)
 *     rank 1   t3(1,4)(2,4)   t4(4,4)(5,4)
 *     rank 2   t5(1,6)(2,6)   t6(4,6)(5,6)
 */
class SeatingRuleCheckerTest extends TestCase
{
    private function room(): SeatingRoom
    {
        $tables = [];
        $index = 1;
        foreach ([2, 4, 6] as $y) {
            foreach ([1, 4] as $x) {
                $tables[] = new SeatingTable('t'.$index++, SeatingFurniture::Double, $x, $y);
            }
        }

        return new SeatingRoom(8, 8, $tables);
    }

    /** @return array<int, SeatingStudent> */
    private function roster(): array
    {
        $roster = [];
        foreach ([1 => 'SLAM', 2 => 'SISR', 3 => 'SLAM', 4 => 'SISR', 5 => 'SLAM'] as $id => $option) {
            $roster[$id] = new SeatingStudent($id, 'Élève', (string) $id, 'Élève '.$id, $option);
        }

        return $roster;
    }

    /**
     * @param list<SeatingRule>  $rules
     * @param array<string, int> $seats
     */
    private function check(array $rules, array $seats, bool $mix = false, bool $front = false): \App\Service\SeatingPlan\SeatingCheck
    {
        return (new SeatingRuleChecker())->check(new SeatingPlanDocument($this->room(), $rules, $mix, $front, $seats), $this->roster());
    }

    public function testSeparatedStudentsMayNotSitDiagonallyEither(): void
    {
        $rule = new SeatingRule('r1', SeatingRuleType::Separate, [1, 2]);

        self::assertFalse($this->check([$rule], ['t1-1' => 1, 't3-0' => 2])->results[0]->ok, 'diagonal, one rank apart');
        self::assertFalse($this->check([$rule], ['t1-0' => 1, 't1-1' => 2])->results[0]->ok, 'same table');
        self::assertTrue($this->check([$rule], ['t1-0' => 1, 't2-0' => 2])->results[0]->ok, 'across the aisle');
    }

    public function testABrokenSeparationMarksBothSeats(): void
    {
        $check = $this->check([new SeatingRule('r1', SeatingRuleType::Separate, [1, 2])], ['t1-0' => 1, 't1-1' => 2]);

        self::assertEqualsCanonicalizing(['t1-0', 't1-1'], $check->faultySeats);
        self::assertSame('seatingCheckNeighbours', $check->results[0]->problems[0]->key);
    }

    public function testAssociatedStudentsSitAtOneTableOrTouchingOnARow(): void
    {
        $rule = new SeatingRule('r1', SeatingRuleType::Together, [1, 2]);

        self::assertTrue($this->check([$rule], ['t1-0' => 1, 't1-1' => 2])->results[0]->ok);
        self::assertFalse($this->check([$rule], ['t1-0' => 1, 't3-0' => 2])->results[0]->ok, 'one behind the other is not side by side');
    }

    public function testTheFrontIsTheFirstTwoRanksThatHoldASeat(): void
    {
        $rule = new SeatingRule('r1', SeatingRuleType::Front, [1]);

        self::assertTrue($this->check([$rule], ['t3-0' => 1])->results[0]->ok, 'second rank');
        $check = $this->check([$rule], ['t5-0' => 1]);
        self::assertFalse($check->results[0]->ok);
        self::assertSame(['%student%' => 'Élève 1', '%rank%' => 3], $check->results[0]->problems[0]->params);
    }

    public function testTheBackIsTheLastRank(): void
    {
        $rule = new SeatingRule('r1', SeatingRuleType::Back, [1]);

        self::assertTrue($this->check([$rule], ['t6-1' => 1])->results[0]->ok);
        self::assertFalse($this->check([$rule], ['t3-1' => 1])->results[0]->ok);
    }

    public function testAnIsolatedStudentHasTheirTableToThemselves(): void
    {
        $rule = new SeatingRule('r1', SeatingRuleType::Alone, [1]);

        self::assertTrue($this->check([$rule], ['t1-0' => 1, 't2-0' => 2])->results[0]->ok);
        self::assertFalse($this->check([$rule], ['t1-0' => 1, 't1-1' => 2])->results[0]->ok);
    }

    public function testAStudentLeftWithoutASeatBreaksTheirRule(): void
    {
        $check = $this->check([new SeatingRule('r1', SeatingRuleType::Front, [1])], ['t1-0' => 2]);

        self::assertFalse($check->results[0]->ok);
        self::assertSame('seatingCheckNoSeat', $check->results[0]->problems[0]->key);
    }

    public function testThePreferencesAreReportedApartFromTheRules(): void
    {
        $check = $this->check([], ['t1-0' => 1, 't1-1' => 3, 't5-0' => 2], mix: true, front: true);

        self::assertSame(0, $check->ruleCount());
        self::assertSame([SeatingRuleResult::PREFERENCE_MIX, SeatingRuleResult::PREFERENCE_FRONT], array_map(static fn (SeatingRuleResult $r): ?string => $r->preference, $check->results));
        self::assertFalse($check->results[0]->ok, 'two SLAM students at t1');
        self::assertFalse($check->results[1]->ok, 'empty seats in front of t5');
    }

    public function testABrokenRuleCostsMoreThanEveryPreferenceTogether(): void
    {
        $checker = new SeatingRuleChecker();
        $room = $this->room();
        $options = array_map(static fn (SeatingStudent $s): ?string => $s->option, $this->roster());
        $rule = [new SeatingRule('r1', SeatingRuleType::Separate, [1, 2])];

        $brokenRule = $checker->penalty($room, $rule, true, true, ['t1-0' => 1, 't1-1' => 2], $options);
        $badPreferences = $checker->penalty($room, $rule, true, true, ['t1-0' => 1, 't1-1' => 3, 't6-0' => 2, 't6-1' => 4], $options);

        self::assertGreaterThan($badPreferences, $brokenRule);
    }
}
