<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\EcoRaceRanking;
use PHPUnit\Framework\TestCase;

/**
 * Who gets a rank in a closed race, and in what order.
 *
 * None of this is visible from the screen it drives: a runner simply appears with a number or
 * without one. The rules behind that - you must have finished, and you must have validated every
 * checkpoint - decide whether somebody's race counted, so they are worth pinning down rather than
 * re-reading out of a 900-line controller.
 */
class EcoRaceRankingTest extends TestCase
{
    private EcoRaceRanking $ranking;

    protected function setUp(): void
    {
        $this->ranking = new EcoRaceRanking();
    }

    public function testFastestCompleteRunTakesFirstPlace(): void
    {
        $ranks = $this->ranking->rank([
            ['id' => 1, 'seconds' => 900, 'validatedCheckpoints' => 5],
            ['id' => 2, 'seconds' => 600, 'validatedCheckpoints' => 5],
            ['id' => 3, 'seconds' => 750, 'validatedCheckpoints' => 5],
        ], 5);

        self::assertSame([2 => 1, 3 => 2, 1 => 3], $ranks);
    }

    public function testARunnerWhoNeverFinishedIsNotRanked(): void
    {
        // No finish time at all - the race is still open for them, or they gave up.
        $ranks = $this->ranking->rank([
            ['id' => 1, 'seconds' => null, 'validatedCheckpoints' => 5],
            ['id' => 2, 'seconds' => 600, 'validatedCheckpoints' => 5],
        ], 5);

        self::assertSame([2 => 1], $ranks);
        self::assertArrayNotHasKey(1, $ranks);
    }

    public function testAnIncompleteRunIsNotRanked(): void
    {
        // Finished, but skipped a checkpoint: the time says nothing comparable.
        $ranks = $this->ranking->rank([
            ['id' => 1, 'seconds' => 300, 'validatedCheckpoints' => 4],
            ['id' => 2, 'seconds' => 600, 'validatedCheckpoints' => 5],
        ], 5);

        self::assertSame([2 => 1], $ranks, 'a faster incomplete run must not outrank a complete one');
    }

    public function testEveryFinisherIsRankedWhenTheParcoursHasNoCheckpoint(): void
    {
        // A parcours with no checkpoint at all cannot make anyone incomplete.
        $ranks = $this->ranking->rank([
            ['id' => 1, 'seconds' => 600, 'validatedCheckpoints' => 0],
            ['id' => 2, 'seconds' => 300, 'validatedCheckpoints' => 0],
        ], 0);

        self::assertSame([2 => 1, 1 => 2], $ranks);
    }

    public function testExtraValidationsDoNotDisqualify(): void
    {
        // Scanning a checkpoint twice is counted once upstream, but a parcours shortened after the
        // race would leave a runner above the total - that is not a reason to drop them.
        $ranks = $this->ranking->rank([['id' => 1, 'seconds' => 600, 'validatedCheckpoints' => 7]], 5);

        self::assertSame([1 => 1], $ranks);
    }

    public function testTiedTimesTakeConsecutiveRanksRatherThanSharingOne(): void
    {
        // Deliberately pinned: two runners on the same second still get 1 and 2, not 1 and 1. The
        // screen shows a position per row, and there is no "ex aequo" rendering to fall back on.
        $ranks = $this->ranking->rank([
            ['id' => 1, 'seconds' => 600, 'validatedCheckpoints' => 5],
            ['id' => 2, 'seconds' => 600, 'validatedCheckpoints' => 5],
        ], 5);

        self::assertSame([1, 2], array_values($ranks));
    }

    public function testNobodyRunningYieldsNoRanks(): void
    {
        self::assertSame([], $this->ranking->rank([], 5));
    }
}
