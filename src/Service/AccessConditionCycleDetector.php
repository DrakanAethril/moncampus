<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Whether saving a condition would close a loop - "A déverrouillé par B déverrouillé par A".
 *
 * Refused at save time rather than survived at read time: a loop produces two objects nobody can
 * ever open, with no error anywhere to say why. Cheap to write here, very expensive to diagnose in
 * production.
 *
 * Pure, over node keys alone ("assignment:17", "quiz_instance:42") - AccessConditionGraph is what
 * turns rows into those keys.
 */
class AccessConditionCycleDetector
{
    /**
     * @param string                      $node         the object about to be saved
     * @param list<string>                $dependencies the objects its new condition points at
     * @param array<string, list<string>> $edges        every other condition already stored, as node => what it points at
     */
    public function wouldCycle(string $node, array $dependencies, array $edges): bool
    {
        // The saved object's own edges are the ones being replaced, so the walk uses the new ones.
        $edges[$node] = $dependencies;

        $seen = [];
        $queue = $dependencies;

        while ([] !== $queue) {
            $current = array_shift($queue);

            if ($current === $node) {
                return true;
            }

            // A loop that already exists further upstream is somebody else's problem, but it still
            // has to be walked out of, or this answers by hanging.
            if (isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;

            foreach ($edges[$current] ?? [] as $next) {
                $queue[] = $next;
            }
        }

        return false;
    }
}
