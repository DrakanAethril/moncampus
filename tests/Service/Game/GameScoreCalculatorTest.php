<?php

declare(strict_types=1);

namespace App\Tests\Service\Game;

use App\Enum\GameFamily;
use App\Service\Game\GameScoreCalculator;
use PHPUnit\Framework\TestCase;

/**
 * The rule of design/validated/gamification.md §2, and nothing else: one is ranked on a rate, and a
 * family with no data leaves the calculation while its weight is redistributed over the others.
 *
 * The empty family is the first case here on purpose - it is what makes the game usable in a class
 * where a single teacher plays it, and it is the one behaviour that cannot be noticed by looking at
 * a screen: an index computed over four families and an index computed over three both read as a
 * number out of 100.
 */
class GameScoreCalculatorTest extends TestCase
{
    private const array WEIGHTS = [
        'attendance' => 30,
        'work' => 30,
        'engagement' => 25,
        'recognition' => 15,
    ];

    private GameScoreCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new GameScoreCalculator();
    }

    public function testAFamilyWithoutDataLeavesAndItsWeightIsRedistributed(): void
    {
        // Nobody made an attendance statement: its 30 spreads over the three others, pro rata.
        $score = $this->calculator->compute(
            ['attendance' => 0, 'work' => 200, 'engagement' => 115, 'recognition' => 70],
            ['attendance' => null, 'work' => 270, 'engagement' => 250, 'recognition' => 150],
            self::WEIGHTS,
        );

        self::assertSame([
            'work' => 43,
            'engagement' => 36,
            'recognition' => 21,
        ], $score->weights, 'the design states 43 / 36 / 21 in so many words');

        self::assertArrayNotHasKey('attendance', $score->rates);

        // 43 x .7407 + 36 x .46 + 21 x .4667 = 58.2
        self::assertSame(58, $score->index);
    }

    public function testTheRedistributedWeightsStillAddUpToOneHundred(): void
    {
        foreach ([['attendance'], ['work', 'engagement'], ['recognition']] as $missing) {
            $possible = ['attendance' => 270, 'work' => 270, 'engagement' => 250, 'recognition' => 150];
            foreach ($missing as $family) {
                $possible[$family] = null;
            }

            $score = $this->calculator->compute(
                ['attendance' => 0, 'work' => 0, 'engagement' => 0, 'recognition' => 0],
                $possible,
                self::WEIGHTS,
            );

            self::assertSame(100, array_sum($score->weights), 'missing: '.implode(', ', $missing));
        }
    }

    public function testAZeroDenominatorEmptiesTheFamilyJustAsANullOneDoes(): void
    {
        $score = $this->calculator->compute(
            ['attendance' => 0, 'work' => 270, 'engagement' => 0, 'recognition' => 0],
            ['attendance' => 0, 'work' => 270, 'engagement' => 250, 'recognition' => 150],
            self::WEIGHTS,
        );

        self::assertArrayNotHasKey('attendance', $score->weights);
        self::assertSame(43, $score->weights['work']);
    }

    public function testEveryFamilyEmptyGivesAZeroIndexRatherThanADivisionByZero(): void
    {
        $score = $this->calculator->compute(
            ['attendance' => 40, 'work' => 30, 'engagement' => 0, 'recognition' => 0],
            ['attendance' => null, 'work' => null, 'engagement' => null, 'recognition' => null],
            self::WEIGHTS,
        );

        self::assertSame(0, $score->index);
        self::assertSame([], $score->rates);
        self::assertSame([], $score->weights);
    }

    public function testAPerfectPeriodIsAnIndexOfOneHundred(): void
    {
        $score = $this->calculator->compute(
            ['attendance' => 270, 'work' => 270, 'engagement' => 250, 'recognition' => 150],
            ['attendance' => 270, 'work' => 270, 'engagement' => 250, 'recognition' => 150],
            self::WEIGHTS,
        );

        self::assertSame(100, $score->index);
    }

    public function testARateNeverExceedsOneEvenWhenABonusOverflowsTheDenominator(): void
    {
        // The attendance streak is credited on top of the net units and is not part of the
        // possible - four consecutive net weeks out of four are still 100 %, never 111 %.
        $score = $this->calculator->compute(
            ['attendance' => 300, 'work' => 0, 'engagement' => 0, 'recognition' => 0],
            ['attendance' => 270, 'work' => 270, 'engagement' => 250, 'recognition' => 150],
            self::WEIGHTS,
        );

        self::assertSame(1.0, $score->rates['attendance']);
        self::assertSame(30, $score->index);
    }

    public function testNoSoldeEverGoesBelowZero(): void
    {
        // §5.6: a malus can take a family below zero in raw points; the rate floors at 0.
        $score = $this->calculator->compute(
            ['attendance' => 0, 'work' => 0, 'engagement' => 0, 'recognition' => -40],
            ['attendance' => 270, 'work' => 270, 'engagement' => 250, 'recognition' => 150],
            self::WEIGHTS,
        );

        self::assertSame(0.0, $score->rates['recognition']);
        self::assertSame(0, $score->index);
    }

    public function testAFamilyAbsentFromTheWeightsIsSimplyNotCounted(): void
    {
        $score = $this->calculator->compute(
            ['work' => 135],
            ['work' => 270],
            ['work' => 30],
        );

        self::assertSame([GameFamily::Work->value => 100], $score->weights);
        self::assertSame(50, $score->index);
    }
}
