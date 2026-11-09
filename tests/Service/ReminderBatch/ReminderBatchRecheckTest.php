<?php

declare(strict_types=1);

namespace App\Tests\Service\ReminderBatch;

use App\Enum\ReminderBatchItemStatus;
use App\Enum\ReminderBatchStep;
use App\Service\AlternanceStepStatus;
use App\Service\ReminderBatch\ReminderBatchRecheck;
use PHPUnit\Framework\TestCase;

/**
 * Read just before each mail (R2): still this person's turn, the mail goes; the livret has moved on
 * past them, « Non envoyée, étape faite »; anything else, « Plus concernée ».
 */
class ReminderBatchRecheckTest extends TestCase
{
    public function testStillTheirTurnSends(): void
    {
        self::assertNull(ReminderBatchRecheck::verdict(ReminderBatchStep::PeriodTutor, AlternanceStepStatus::STEP_TUTOR));
        self::assertNull(ReminderBatchRecheck::verdict(ReminderBatchStep::EngagementStudent, AlternanceStepStatus::STEP_ENGAGEMENT_STUDENT));
    }

    public function testASignatureInBetweenIsTheStepDone(): void
    {
        self::assertSame(ReminderBatchItemStatus::AlreadyDone, ReminderBatchRecheck::verdict(ReminderBatchStep::PeriodTutor, AlternanceStepStatus::STEP_STUDENT));
        self::assertSame(ReminderBatchItemStatus::AlreadyDone, ReminderBatchRecheck::verdict(ReminderBatchStep::PeriodStudent, AlternanceStepStatus::STEP_CLOSED));
        self::assertSame(ReminderBatchItemStatus::AlreadyDone, ReminderBatchRecheck::verdict(ReminderBatchStep::EngagementTutor, AlternanceStepStatus::STEP_ENGAGEMENT_STUDENT));
        // The engagement done, the alternance is into its bilans.
        self::assertSame(ReminderBatchItemStatus::AlreadyDone, ReminderBatchRecheck::verdict(ReminderBatchStep::EngagementStudent, AlternanceStepStatus::STEP_TUTOR));
    }

    public function testAnEndedAlternanceOrAClosedDoorIsNoLongerConcerned(): void
    {
        self::assertSame(ReminderBatchItemStatus::NotConcerned, ReminderBatchRecheck::verdict(ReminderBatchStep::PeriodTutor, AlternanceStepStatus::STEP_INACTIVE));
        self::assertSame(ReminderBatchItemStatus::NotConcerned, ReminderBatchRecheck::verdict(ReminderBatchStep::PeriodTutor, AlternanceStepStatus::STEP_NOT_OPENED));
        self::assertSame(ReminderBatchItemStatus::NotConcerned, ReminderBatchRecheck::verdict(ReminderBatchStep::EngagementTutor, AlternanceStepStatus::STEP_INACTIVE));
    }

    public function testGoingBackwardIsNotADoneStep(): void
    {
        // The alternant's turn in the batch, but the tutor's evaluation is open again: nobody did
        // the alternant's part, and the mail would ask for something they cannot do yet.
        self::assertSame(ReminderBatchItemStatus::NotConcerned, ReminderBatchRecheck::verdict(ReminderBatchStep::PeriodStudent, AlternanceStepStatus::STEP_TUTOR));
    }
}
