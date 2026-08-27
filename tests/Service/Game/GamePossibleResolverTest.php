<?php

declare(strict_types=1);

namespace App\Tests\Service\Game;

use App\Service\Game\GamePossibleResolver;
use PHPUnit\Framework\TestCase;

/**
 * What it was *possible* to earn, family by family (design/validated/gamification.md §2).
 *
 * Pure arithmetic on primitives, deliberately: the denominator is the half of the index nobody can
 * check by looking at a screen, and it is the half that decides whether a work-placement student is
 * ranked on their behaviour or on their availability.
 */
class GamePossibleResolverTest extends TestCase
{
    private GamePossibleResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new GamePossibleResolver();
    }

    public function testAnAttendanceFamilyNobodyStatedIsNullRatherThanZero(): void
    {
        self::assertNull($this->resolver->attendance(0, 30));
    }

    public function testTheAttendancePossibleCountsTheUnitsThatConcernedTheStudent(): void
    {
        // Nine weeks counted, one of them out of scope: the out-of-scope one is not passed in.
        self::assertSame(270, $this->resolver->attendance(9, 30));
    }

    public function testAMonthlyStepIsTheSameArithmeticOnFewerUnits(): void
    {
        // A month covering four weeks is one unit worth four times the weekly value; what the
        // resolver is handed is already the points of one unit, so nothing here knows the step.
        self::assertSame(480, $this->resolver->attendance(4, 120));
    }

    public function testAStudentWithNoDeadlineHasNoWorkFamily(): void
    {
        self::assertNull($this->resolver->work([]));
    }

    public function testTheWorkPossibleIsTheSumOfWhatEachDeadlineCouldHavePaid(): void
    {
        // Nine deposits at 30 - the mockup's "9 échéances - 200/270".
        self::assertSame(270, $this->resolver->work(array_fill(0, 9, 30)));

        // Mixed natures: a deposit, an évaluation quiz, a self-assessment.
        self::assertSame(60, $this->resolver->work([30, 20, 10]));
    }

    public function testFourDeadlinesHonouredOutOfFourAreWorthAsMuchAsTwelveOutOfTwelve(): void
    {
        $four = $this->resolver->work(array_fill(0, 4, 30));
        $twelve = $this->resolver->work(array_fill(0, 12, 30));

        self::assertNotNull($four);
        self::assertNotNull($twelve);
        self::assertEqualsWithDelta(1.0, 4 * 30 / $four, 1e-9);
        self::assertEqualsWithDelta(1.0, 12 * 30 / $twelve, 1e-9);
    }

    public function testTheTwoFlatFamiliesAreTheSameForEverybody(): void
    {
        self::assertSame(250, $this->resolver->flat(250));
        self::assertSame(150, $this->resolver->flat(150));
    }

    public function testAFlatCapSetToZeroSwitchesItsFamilyOff(): void
    {
        // The only way to take engagement or recognition out of the index, and it is a setting
        // rather than a special case: a cap of zero is a family with no possible.
        self::assertNull($this->resolver->flat(0));
    }

    public function testAllFourFamiliesReadTogether(): void
    {
        self::assertSame(
            ['attendance' => 270, 'work' => 60, 'engagement' => 250, 'recognition' => 150],
            $this->resolver->resolve(9, 30, [30, 20, 10], 250, 150),
        );
    }

    public function testTheStudentWhoArrivedInNovemberSimplyHasASmallerPossible(): void
    {
        // Same behaviour, half the occasions: the rate is what makes the two comparable.
        $newcomer = $this->resolver->resolve(4, 30, [30, 30], 250, 150);

        self::assertSame(120, $newcomer['attendance']);
        self::assertSame(60, $newcomer['work']);
        self::assertSame(250, $newcomer['engagement'], 'the volunteering cap is not prorated: the occasion is the same');
    }
}
