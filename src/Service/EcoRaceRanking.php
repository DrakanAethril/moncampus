<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The classification of a closed race: who gets a position, and in what order.
 *
 * Two conditions, both deliberate. A runner has to have finished - without a finish time there is
 * no duration to compare. And they have to have validated every checkpoint of the parcours: an
 * orienteering time only means something over the whole course, so a fast run that skipped a
 * control is not a fast run, it is a different one. Both cases still appear on the results screen,
 * simply without a position.
 *
 * Extracted out of App\Controller\EcoCourseController, which walked the entity graph and applied
 * the rule in the same method. It takes runs already reduced to figures so the rule can be read -
 * and tested - without building a course, a parcours and a scan history around it.
 */
final class EcoRaceRanking
{
    /**
     * @param list<array{id: int, seconds: ?int, validatedCheckpoints: int}> $runs
     * @param int                                                            $checkpointTotal the parcours' own count; 0 disables the completeness condition
     *
     * @return array<int, int> runner id => position, starting at 1
     */
    public function rank(array $runs, int $checkpointTotal): array
    {
        $ranked = [];

        foreach ($runs as $run) {
            if (null === $run['seconds']) {
                continue;
            }

            if ($checkpointTotal > 0 && $run['validatedCheckpoints'] < $checkpointTotal) {
                continue;
            }

            $ranked[] = $run;
        }

        usort($ranked, static fn (array $a, array $b): int => $a['seconds'] <=> $b['seconds']);

        $ranks = [];
        foreach ($ranked as $index => $run) {
            // Consecutive, not shared: the screen prints one position per row and has no ex aequo
            // rendering, so two runners on the same second still read as 1 and 2.
            $ranks[$run['id']] = $index + 1;
        }

        return $ranks;
    }
}
