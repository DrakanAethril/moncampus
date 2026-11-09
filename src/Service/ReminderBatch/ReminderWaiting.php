<?php

declare(strict_types=1);

namespace App\Service\ReminderBatch;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipTutorLink;
use App\Entity\Program;

/**
 * One alternance whose livret waits on somebody a reminder can reach - the tutor or the alternant -
 * as AlternanceReminderBoard finds it. $period is the bilan the target's rank designates in this
 * formation, null for the engagement.
 */
final readonly class ReminderWaiting
{
    public function __construct(
        public Program $program,
        public InternshipTutorLink $tutorLink,
        public ReminderBatchTarget $target,
        public ?InternshipEvaluationPeriod $period,
    ) {
    }
}
