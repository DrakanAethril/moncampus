<?php

declare(strict_types=1);

namespace App\Service\ReminderBatch;

use App\Enum\ReminderBatchExclusion;

/**
 * Who a batch takes, decided on primitives: the alternances its step waits on, against the ones
 * an active batch already holds for that very step (R3) and the ones nobody can be written to
 * (R9). Nobody is dropped - a person set aside is a line with its reason.
 *
 * The same answer serves the count shown under the form and the launch that freezes it, which is
 * what lets the launch refuse a count that moved in between.
 *
 * @phpstan-type Candidate array{tutorLinkId: int, programId: int, hasAddress: bool}
 */
final class ReminderBatchPlanner
{
    /**
     * @param list<Candidate>  $candidates
     * @param array<int, true> $queuedLinkIds tutor link id => already waiting for this target in a running or paused batch
     */
    public function plan(array $candidates, array $queuedLinkIds): ReminderBatchPlan
    {
        $lines = [];
        $seen = [];

        foreach ($candidates as $candidate) {
            $linkId = $candidate['tutorLinkId'];
            if (isset($seen[$linkId])) {
                continue;
            }
            $seen[$linkId] = true;

            // The other batch first: somebody is already chasing this person, which is the thing
            // to know, address or not.
            $exclusion = match (true) {
                isset($queuedLinkIds[$linkId]) => ReminderBatchExclusion::InActiveBatch,
                !$candidate['hasAddress'] => ReminderBatchExclusion::NoAddress,
                default => null,
            };

            $lines[] = ['tutorLinkId' => $linkId, 'programId' => $candidate['programId'], 'exclusion' => $exclusion];
        }

        return new ReminderBatchPlan($lines);
    }
}
