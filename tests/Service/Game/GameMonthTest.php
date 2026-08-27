<?php

declare(strict_types=1);

namespace App\Tests\Service\Game;

use App\Service\Game\GameMonth;
use PHPUnit\Framework\TestCase;

/**
 * The scoring window, now that it is a calendar month rather than an evaluation period.
 *
 * A month is the same for everybody, needs no setting up, and nobody has to ask which one they are
 * in - which is the whole reason it replaced the period.
 */
class GameMonthTest extends TestCase
{
    public function testAMonthKnowsItsOwnBounds(): void
    {
        $month = GameMonth::of(new \DateTimeImmutable('2026-02-14 09:30'));

        self::assertSame('2026-02', $month->key());
        self::assertSame('2026-02-01 00:00:00', $month->firstDay()->format('Y-m-d H:i:s'));
        // February, and a leap year at that: the bound is computed, never assumed.
        self::assertSame('2026-02-28 23:59:59', $month->lastMoment()->format('Y-m-d H:i:s'));
    }

    public function testTheKeySortsChronologically(): void
    {
        $keys = ['2026-09', '2026-10', '2027-01'];
        $shuffled = ['2027-01', '2026-09', '2026-10'];
        sort($shuffled);

        self::assertSame($keys, $shuffled, 'YYYY-MM is what makes a plain string sort a calendar');
    }

    public function testWalkingBackAndForwardCrossesTheYear(): void
    {
        $january = GameMonth::fromKey('2027-01');
        self::assertNotNull($january);

        self::assertSame('2026-12', $january->previous()->key());
        self::assertSame('2027-02', $january->next()->key());
    }

    public function testOnlyAWellFormedKeyResolves(): void
    {
        self::assertNotNull(GameMonth::fromKey('2026-01'));
        self::assertNull(GameMonth::fromKey('2026-13'));
        self::assertNull(GameMonth::fromKey('2026-00'));
        self::assertNull(GameMonth::fromKey('202609'));
        self::assertNull(GameMonth::fromKey(''));
    }

    public function testOnlyAFinishedMonthCanBeClosed(): void
    {
        $now = new \DateTimeImmutable('2026-09-15 12:00');

        self::assertTrue(GameMonth::fromKey('2026-08')?->hasEnded($now));
        self::assertFalse(GameMonth::fromKey('2026-09')?->hasEnded($now), 'the month in progress is not over');
        self::assertFalse(GameMonth::fromKey('2026-10')?->hasEnded($now));
    }
}
