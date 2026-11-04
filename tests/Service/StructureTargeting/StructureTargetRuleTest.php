<?php

declare(strict_types=1);

namespace App\Tests\Service\StructureTargeting;

use App\Service\StructureTargeting\StructureProgramFacts;
use App\Service\StructureTargeting\StructureTargetRule;
use App\Service\StructureTargeting\StructureTargets;
use PHPUnit\Framework\TestCase;

/**
 * « Est-ce que ce ciblage atteint cet étudiant ? » (design/validated/quiz-tournament.md §2 and §14),
 * on primitives: a program is reached when it is active and targeted itself, or sits under a targeted
 * filière or section; a test account is reached through test programs only.
 */
class StructureTargetRuleTest extends TestCase
{
    public function testATargetedProgramIsReached(): void
    {
        self::assertTrue(StructureTargetRule::reachesProgram(new StructureTargets([], [], [10]), $this->program(10, 2, 1)));
    }

    public function testAProgramUnderATargetedTrackIsReached(): void
    {
        self::assertTrue(StructureTargetRule::reachesProgram(new StructureTargets([], [2], []), $this->program(10, 2, 1)));
    }

    public function testAProgramUnderATargetedSectionIsReached(): void
    {
        self::assertTrue(StructureTargetRule::reachesProgram(new StructureTargets([1], [], []), $this->program(10, 2, 1)));
    }

    public function testAProgramOutsideEveryTargetIsNotReached(): void
    {
        self::assertFalse(StructureTargetRule::reachesProgram(new StructureTargets([9], [8], [7]), $this->program(10, 2, 1)));
    }

    public function testAnInactiveProgramIsNeverReached(): void
    {
        self::assertFalse(StructureTargetRule::reachesProgram(new StructureTargets([1], [2], [10]), $this->program(10, 2, 1, active: false)));
    }

    public function testAnEmptyTargetReachesNobody(): void
    {
        $targets = new StructureTargets([], [], []);

        self::assertTrue($targets->isEmpty());
        self::assertFalse(StructureTargetRule::reaches($targets, [$this->program(10, 2, 1)], false));
    }

    public function testAStudentIsReachedThroughAnyOfTheirPrograms(): void
    {
        $targets = new StructureTargets([], [], [11]);

        self::assertTrue(StructureTargetRule::reaches($targets, [$this->program(10, 2, 1), $this->program(11, 3, 1)], false));
        self::assertFalse(StructureTargetRule::reaches($targets, [$this->program(10, 2, 1)], false));
    }

    public function testATestAccountIsReachedThroughATestProgramOnly(): void
    {
        $targets = new StructureTargets([1], [], []);

        self::assertFalse(StructureTargetRule::reaches($targets, [$this->program(10, 2, 1)], true));
        self::assertTrue(StructureTargetRule::reaches($targets, [$this->program(10, 2, 1, test: true)], true));
    }

    public function testARealAccountIsReachedThroughATestProgramToo(): void
    {
        // The asymmetric rule of StructureAccessChecker::matchesTestMode().
        self::assertTrue(StructureTargetRule::reaches(new StructureTargets([1], [], []), [$this->program(10, 2, 1, test: true)], false));
    }

    public function testTheReachedProgramsComeMostRecentFirst(): void
    {
        $targets = new StructureTargets([1], [], []);
        $reached = StructureTargetRule::reachedPrograms($targets, [
            $this->program(10, 2, 1),
            $this->program(30, 2, 1),
            $this->program(20, 4, 5),
            $this->program(25, 2, 1, active: false),
            $this->program(15, 2, 1),
        ], false);

        self::assertSame([30, 15, 10], array_map(static fn (StructureProgramFacts $program): int => $program->programId, $reached));
    }

    private function program(int $id, int $track, int $section, bool $active = true, bool $test = false): StructureProgramFacts
    {
        return new StructureProgramFacts($id, $track, $section, $active, $test);
    }
}
