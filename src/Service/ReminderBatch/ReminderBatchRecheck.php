<?php

declare(strict_types=1);

namespace App\Service\ReminderBatch;

use App\Enum\ReminderBatchItemStatus;
use App\Enum\ReminderBatchStep;
use App\Service\AlternanceStepStatus;

/**
 * What a frozen line becomes once the resolver has said where its alternance stands now (R2): the
 * freeze says who *may* get a mail, the resolver who still *should*.
 *
 * Null means the mail goes. The livret moved past the batch's step: « Non envoyée, étape faite ».
 * Anything else - an ended alternance, a bilan that is no longer open to them, a step gone back -
 * « Plus concernée ». Which step comes after which is the livret's order, written below once.
 */
final class ReminderBatchRecheck
{
    private const array ENGAGEMENT_ORDER = [
        AlternanceStepStatus::STEP_ENGAGEMENT_TUTOR,
        AlternanceStepStatus::STEP_ENGAGEMENT_STUDENT,
        AlternanceStepStatus::STEP_ENGAGEMENT_CENTER,
        AlternanceStepStatus::STEP_TUTOR,
        AlternanceStepStatus::STEP_STUDENT,
        AlternanceStepStatus::STEP_SUPERVISOR,
        AlternanceStepStatus::STEP_CLOSED,
    ];

    private const array PERIOD_ORDER = [
        AlternanceStepStatus::STEP_TUTOR,
        AlternanceStepStatus::STEP_STUDENT,
        AlternanceStepStatus::STEP_SUPERVISOR,
        AlternanceStepStatus::STEP_CLOSED,
    ];

    public static function verdict(ReminderBatchStep $step, string $statusStep): ?ReminderBatchItemStatus
    {
        if ($statusStep === $step->statusStep()) {
            return null;
        }

        $order = $step->isPeriod() ? self::PERIOD_ORDER : self::ENGAGEMENT_ORDER;
        $expected = array_search($step->statusStep(), $order, true);
        $current = array_search($statusStep, $order, true);

        return false !== $expected && false !== $current && $current > $expected
            ? ReminderBatchItemStatus::AlreadyDone
            : ReminderBatchItemStatus::NotConcerned;
    }
}
