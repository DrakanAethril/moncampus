<?php

declare(strict_types=1);

namespace App\Tests\Service\SeatingPlan;

use App\Enum\SeatingRuleType;
use App\Service\SeatingPlan\SeatingPlanDocument;
use App\Service\SeatingPlan\SeatingPlanSolver;
use App\Service\SeatingPlan\SeatingRoom;
use App\Service\SeatingPlan\SeatingRoomTemplates;
use App\Service\SeatingPlan\SeatingRule;
use App\Service\SeatingPlan\SeatingRuleChecker;
use App\Service\SeatingPlan\SeatingStudent;
use PHPUnit\Framework\TestCase;

/**
 * A class of 24 - twelve SLAM, twelve SISR - in the « rangées » room (28 seats).
 */
class SeatingPlanSolverTest extends TestCase
{
    /** @return array<int, SeatingStudent> */
    private function roster(int $size = 24): array
    {
        $roster = [];
        for ($id = 1; $id <= $size; ++$id) {
            $roster[$id] = new SeatingStudent($id, 'Élève', (string) $id, 'Élève '.$id, 0 === $id % 2 ? 'SISR' : 'SLAM');
        }

        return $roster;
    }

    private function document(SeatingRoom $room, array $rules = [], array $seats = [], array $pins = []): SeatingPlanDocument
    {
        return new SeatingPlanDocument($room, $rules, true, true, $seats, $pins);
    }

    /** @return list<SeatingRule> */
    private function rules(): array
    {
        return [
            new SeatingRule('r1', SeatingRuleType::Front, [4, 17, 21]),
            new SeatingRule('r2', SeatingRuleType::Separate, [1, 3]),
            new SeatingRule('r3', SeatingRuleType::Separate, [19, 23, 11]),
            new SeatingRule('r4', SeatingRuleType::Together, [2, 14]),
        ];
    }

    public function testItSeatsEveryStudentOnceAndKeepsEveryRule(): void
    {
        $checker = new SeatingRuleChecker();
        $document = $this->document((new SeatingRoomTemplates())->rows(), $this->rules());

        $seats = (new SeatingPlanSolver($checker))->propose($document, $this->roster(), 3);

        self::assertCount(24, $seats);
        self::assertCount(24, array_unique($seats));
        $check = $checker->check($document->withSeats($seats, 3), $this->roster());
        self::assertSame(0, $check->brokenRuleCount());
    }

    public function testTheSameSeedGivesTheSamePlanAndAnotherSeedAnotherOne(): void
    {
        $solver = new SeatingPlanSolver(new SeatingRuleChecker());
        $document = $this->document((new SeatingRoomTemplates())->rows(), $this->rules());

        $first = $solver->propose($document, $this->roster(), 7);

        self::assertSame($first, $solver->propose($document, $this->roster(), 7));
        self::assertNotSame($first, $solver->propose($document, $this->roster(), 8));
    }

    public function testALockedStudentKeepsTheirSeat(): void
    {
        $solver = new SeatingPlanSolver(new SeatingRuleChecker());
        $document = $this->document((new SeatingRoomTemplates())->rows(), $this->rules(), ['t1-0' => 9], ['t1-0']);

        foreach ([1, 2, 3] as $seed) {
            $seats = $solver->propose($document, $this->roster(), $seed);
            self::assertSame(9, $seats['t1-0']);
            self::assertSame(['t1-0'], array_keys($seats, 9, true));
        }
    }

    public function testAClassLargerThanTheRoomLeavesStudentsStanding(): void
    {
        $seats = (new SeatingPlanSolver(new SeatingRuleChecker()))->propose($this->document((new SeatingRoomTemplates())->islands()), $this->roster(30), 1);

        self::assertCount(24, $seats);
    }

    public function testAnEmptyRoomSeatsNobody(): void
    {
        self::assertSame([], (new SeatingPlanSolver(new SeatingRuleChecker()))->propose($this->document(SeatingRoom::empty()), $this->roster(), 1));
    }
}
