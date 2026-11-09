<?php

declare(strict_types=1);

namespace App\Tests\Service\ReminderBatch;

use App\Enum\ReminderBatchExclusion;
use App\Service\ReminderBatch\ReminderBatchPlanner;
use PHPUnit\Framework\TestCase;

/**
 * What a batch freezes at launch (R1, R3, R9): everybody the step waits on in the chosen
 * formations, each either to send or set aside with its reason - never silently dropped.
 */
class ReminderBatchPlannerTest extends TestCase
{
    public function testEverybodyWithAnAddressAndInNoOtherBatchIsToSend(): void
    {
        $plan = (new ReminderBatchPlanner())->plan([
            ['tutorLinkId' => 1, 'programId' => 10, 'hasAddress' => true],
            ['tutorLinkId' => 2, 'programId' => 10, 'hasAddress' => true],
        ], []);

        self::assertSame(2, $plan->toSendCount());
        self::assertSame(0, $plan->excludedCount());
        self::assertSame([null, null], array_column($plan->lines, 'exclusion'));
    }

    public function testNobodyToWriteToIsSetAsideAndSaysWhy(): void
    {
        $plan = (new ReminderBatchPlanner())->plan([
            ['tutorLinkId' => 1, 'programId' => 10, 'hasAddress' => false],
        ], []);

        self::assertSame(0, $plan->toSendCount());
        self::assertSame(ReminderBatchExclusion::NoAddress, $plan->lines[0]['exclusion']);
    }

    public function testSomebodyAlreadyWaitingInAnActiveBatchIsNotTakenTwice(): void
    {
        // Two colleagues launching the same step the same morning: the second batch shows the
        // person, set aside, rather than mailing them a second time.
        $plan = (new ReminderBatchPlanner())->plan([
            ['tutorLinkId' => 1, 'programId' => 10, 'hasAddress' => true],
            ['tutorLinkId' => 2, 'programId' => 10, 'hasAddress' => true],
        ], [2 => true]);

        self::assertSame(1, $plan->toSendCount());
        self::assertSame(ReminderBatchExclusion::InActiveBatch, $plan->lines[1]['exclusion']);
    }

    public function testTheOtherBatchIsTheReasonGivenEvenWithoutAnAddress(): void
    {
        // Somebody else's batch is handling the person already: that is what to read first.
        $plan = (new ReminderBatchPlanner())->plan([
            ['tutorLinkId' => 1, 'programId' => 10, 'hasAddress' => false],
        ], [1 => true]);

        self::assertSame(ReminderBatchExclusion::InActiveBatch, $plan->lines[0]['exclusion']);
    }

    public function testAnAlternanceIsOneLineHoweverOftenItIsOffered(): void
    {
        $plan = (new ReminderBatchPlanner())->plan([
            ['tutorLinkId' => 1, 'programId' => 10, 'hasAddress' => true],
            ['tutorLinkId' => 1, 'programId' => 10, 'hasAddress' => true],
        ], []);

        self::assertCount(1, $plan->lines);
    }

    public function testTheFiguresAreGivenPerFormation(): void
    {
        $plan = (new ReminderBatchPlanner())->plan([
            ['tutorLinkId' => 1, 'programId' => 10, 'hasAddress' => true],
            ['tutorLinkId' => 2, 'programId' => 10, 'hasAddress' => false],
            ['tutorLinkId' => 3, 'programId' => 20, 'hasAddress' => true],
        ], []);

        self::assertSame([
            10 => ['waiting' => 2, 'toSend' => 1, 'excluded' => 1],
            20 => ['waiting' => 1, 'toSend' => 1, 'excluded' => 0],
        ], $plan->countsByProgram());
        self::assertSame(3, $plan->waitingCount());
    }

    public function testNobodyWaitingIsAnEmptyPlan(): void
    {
        $plan = (new ReminderBatchPlanner())->plan([], []);

        self::assertTrue($plan->isEmpty());
        self::assertSame([], $plan->countsByProgram());
    }
}
