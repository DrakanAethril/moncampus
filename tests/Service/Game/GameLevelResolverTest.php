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

    public function testTheThresholdsOfTheDesignAreWhatDecidesALevel(): void
    {
        // The six thresholds are the establishment's, and a level is read off a running total of
        // points - there is no per-period conversion left to calibrate.
        self::assertSame(1, $this->resolver->resolve(0)->level->level);
        self::assertSame(2, $this->resolver->resolve(300)->level->level);
        self::assertSame(3, $this->resolver->resolve(700)->level->level);
        self::assertSame(4, $this->resolver->resolve(1200)->level->level);
        self::assertSame(5, $this->resolver->resolve(1800)->level->level);
        self::assertSame(6, $this->resolver->resolve(2500)->level->level);
    }
}
