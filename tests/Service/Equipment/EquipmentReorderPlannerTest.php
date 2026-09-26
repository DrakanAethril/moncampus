<?php

declare(strict_types=1);

namespace App\Tests\Service\Equipment;

use App\Service\Equipment\EquipmentReorderPlanner;
use PHPUnit\Framework\TestCase;

/**
 * « À commander »: which types need re-ordering, how many, and when the reserve runs dry.
 *
 * What is already ordered counts as coming: a type under its threshold whose order covers it is not
 * flagged again - suggesting the same order twice is exactly what the « en commande » state is for.
 */
class EquipmentReorderPlannerTest extends TestCase
{
    private const string TODAY = '2026-09-26';

    public function testUnderTheThresholdIsFlaggedWithTheQuantityBackToTarget(): void
    {
        $line = $this->plan(available: 2, onOrder: 0, threshold: 3, target: 10, consumed: 0);

        self::assertTrue($line->needsOrder);
        self::assertSame(8, $line->suggested);
    }

    public function testWhatIsAlreadyOrderedCoversTheNeed(): void
    {
        $line = $this->plan(available: 2, onOrder: 8, threshold: 3, target: 10, consumed: 0);

        self::assertFalse($line->needsOrder, 'the order in progress brings it back above the threshold');
        self::assertTrue($line->coveredByOrder);
        self::assertSame(0, $line->suggested);
    }

    public function testNothingLeftIsAlwaysFlagged(): void
    {
        $line = $this->plan(available: 0, onOrder: 0, threshold: null, target: null, consumed: 0);

        self::assertTrue($line->needsOrder);
        self::assertNull($line->suggested, 'no target, no quantity to suggest');
    }

    /** 18 pieces taken over six months is 3 a month: 6 left run out in about two months. */
    public function testTheRunOutDateFollowsTheConsumption(): void
    {
        $line = $this->plan(available: 6, onOrder: 0, threshold: null, target: null, consumed: 18);

        self::assertEqualsWithDelta(3.0, $line->monthlyConsumption, 0.01);
        self::assertNotNull($line->runsOutOn);
        self::assertSame('2026-11-25', $line->runsOutOn->format('Y-m-d'));
    }

    /** Running out within a month, with nothing ordered, is a reason to order even above the threshold. */
    public function testRunningOutSoonIsFlagged(): void
    {
        $line = $this->plan(available: 4, onOrder: 0, threshold: 1, target: 12, consumed: 60);

        self::assertTrue($line->needsOrder);
        self::assertSame(8, $line->suggested);
    }

    public function testNoConsumptionNoRunOutDate(): void
    {
        $line = $this->plan(available: 5, onOrder: 0, threshold: 1, target: 5, consumed: 0);

        self::assertNull($line->runsOutOn);
        self::assertFalse($line->needsOrder);
    }

    private function plan(int $available, int $onOrder, ?int $threshold, ?int $target, int $consumed): \App\Service\Equipment\EquipmentReorderLine
    {
        return (new EquipmentReorderPlanner())->plan($available, $onOrder, $threshold, $target, $consumed, new \DateTimeImmutable(self::TODAY));
    }
}
