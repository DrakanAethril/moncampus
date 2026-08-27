<?php

declare(strict_types=1);

namespace App\Tests\Service\Game;

use App\Service\Game\GameLevelResolver;
use App\Service\Game\GameLevels;
use PHPUnit\Framework\TestCase;

/**
 * The six levels, and the coefficient that must never be written as a 9.
 */
class GameLevelResolverTest extends TestCase
{
    private GameLevelResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new GameLevelResolver();
    }

    public function testTheThresholdsAreTheHandoffOnes(): void
    {
        self::assertSame([0, 300, 700, 1200, 1800, 2500], array_map(
            static fn ($level): int => $level->xpMin,
            GameLevels::all(),
        ));
    }

    public function testAnXpTotalLandsOnItsLevelAndKnowsTheNextOne(): void
    {
        // The handoff's demo student carries 860 XP and calls itself level 2; on the thresholds
        // that same file states, 860 is level 3. The thresholds win - they are what the ring is
        // drawn from - and the demo figure is simply stale.
        $progress = $this->resolver->resolve(860);

        self::assertSame(3, $progress->level->level);
        self::assertSame(4, $progress->next?->level);
        self::assertSame(160, $progress->xpIntoLevel);
        self::assertSame(340, $progress->xpToNext);
        self::assertEqualsWithDelta(0.32, $progress->progress, 1e-9);
    }

    public function testLevelSixStaysFullRatherThanEmptyingItsOwnBar(): void
    {
        $progress = $this->resolver->resolve(3600);

        self::assertSame(6, $progress->level->level);
        self::assertTrue($progress->isMaxed());
        self::assertSame(1.0, $progress->progress);
        self::assertNull($progress->xpToNext);
    }

    public function testAPerfectCursusIsThirtySixHundredXpWhateverTheNumberOfPeriods(): void
    {
        foreach ([2, 3, 4, 6, 12] as $periodCount) {
            $total = 0;
            for ($i = 0; $i < $periodCount; ++$i) {
                $total += $this->resolver->xpForIndex(100, $periodCount);
            }

            self::assertSame(GameLevels::CURSUS_CAP, $total, $periodCount.' periods');
        }
    }

    public function testTheCalibrationOfTheDesignHoldsOnFourSemesters(): void
    {
        // §4, decision 2: the table of "indice moyen -> XP par période" on a x9 coefficient.
        self::assertSame(855, $this->resolver->xpForIndex(95, 4));
        self::assertSame(648, $this->resolver->xpForIndex(72, 4));
        self::assertSame(450, $this->resolver->xpForIndex(50, 4));
        self::assertSame(270, $this->resolver->xpForIndex(30, 4));

        // A regular student reaches level 6 on the fourth semester, not the perfect one on the first.
        self::assertSame(6, $this->resolver->resolve(4 * 648)->level->level);
        self::assertSame(5, $this->resolver->resolve(3 * 648)->level->level);
    }

    public function testATermBasedCursusReachesTheSameCeiling(): void
    {
        self::assertSame(6.0, $this->resolver->coefficient(6));
        self::assertSame(570, $this->resolver->xpForIndex(95, 6));
    }

    public function testAFormationWithoutAPeriodGroupPaysNothingRatherThanGuessing(): void
    {
        self::assertSame(0, $this->resolver->xpForIndex(95, 0));
        self::assertNull($this->resolver->coefficient(0));
        self::assertSame(9.0, $this->resolver->coefficient(4));
    }
}
