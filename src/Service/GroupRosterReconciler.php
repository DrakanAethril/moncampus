<?php

declare(strict_types=1);

namespace App\Service;

/**
 * « Recalculer l'effectif » - brings a set of groups back onto the class as it stands today, without
 * re-drawing it. A lot saved in September and reopened in January describes a class that has since
 * lost and gained students, and the teacher's arrangement is worth keeping: everybody who is still
 * there stays exactly where they were placed.
 *
 * The two halves are deliberately asymmetric:
 *
 * - Leaving takes a student out of wherever they sat, a locked group included. A lock says "do not
 *   re-draw this group", never "this person is still in the class".
 * - Joining only ever fills a group the teacher left open, and the newcomers who find no open group
 *   come back as $unplaced rather than being forced in or silently dropped.
 *
 * Placement is smallest-group-first, ties going to the earliest group, and nothing is shuffled: this
 * is a repair, not a draw (App\Service\GroupCreationService is the draw, and it empties every
 * unlocked group before placing, which is precisely what must NOT happen here). Clicking twice must
 * show the same screen twice.
 *
 * Membership and eligibility are two different lists on purpose. $rosterIds alone decides who is
 * removed - being outside the panel's Option filter, or ticked absent, is not leaving the class.
 * $placeableIds is the narrower one, and only decides who may be handed a new seat.
 *
 * Everything travels as plain student ids: the reconciliation is arithmetic over a seating chart,
 * and the caller is what knows about Users (same reasoning as GroupCreationService's own signature).
 */
class GroupRosterReconciler
{
    /**
     * @param list<list<int>> $groups       the groups as they stand, in order, as student ids
     * @param list<int>       $rosterIds    every student the class currently holds - the only thing
     *                                      a removal is decided on
     * @param list<int>       $placeableIds the students eligible for a new seat, in the order they
     *                                      should be handed one (the panel's Option scope, absents
     *                                      excluded); a subset of $rosterIds
     * @param list<int>       $lockedIndices positions in $groups the teacher pinned - never given a
     *                                      newcomer
     *
     * @return array{groups: list<list<int>>, removed: list<int>, added: list<int>, unplaced: list<int>}
     *         $groups keeps its count and its order, an emptied group staying as an empty group -
     *         dropping it would renumber every group after it
     */
    public function reconcile(array $groups, array $rosterIds, array $placeableIds, array $lockedIndices = []): array
    {
        $roster = array_fill_keys($rosterIds, true);
        $lockedLookup = array_fill_keys($lockedIndices, true);

        $seated = [];
        $removed = [];
        $kept = [];

        foreach ($groups as $members) {
            $group = [];
            foreach ($members as $studentId) {
                if (!isset($roster[$studentId])) {
                    $removed[] = $studentId;
                    continue;
                }

                // A student sitting in two groups at once is broken data, never an arrangement:
                // keeping the first seat is the only reading that leaves them in exactly one group.
                if (isset($seated[$studentId])) {
                    continue;
                }

                $seated[$studentId] = true;
                $group[] = $studentId;
            }
            $kept[] = $group;
        }

        $newcomers = array_values(array_filter($placeableIds, static fn (int $studentId): bool => !isset($seated[$studentId])));

        $openIndices = array_values(array_filter(array_keys($kept), static fn (int $index): bool => !isset($lockedLookup[$index])));
        if ([] === $openIndices) {
            return ['groups' => $kept, 'removed' => $removed, 'added' => [], 'unplaced' => $newcomers];
        }

        foreach ($newcomers as $studentId) {
            $kept[$this->smallestOpenGroup($kept, $openIndices)][] = $studentId;
        }

        return ['groups' => $kept, 'removed' => $removed, 'added' => $newcomers, 'unplaced' => []];
    }

    /**
     * @param list<list<int>> $groups
     * @param non-empty-list<int> $openIndices
     */
    private function smallestOpenGroup(array $groups, array $openIndices): int
    {
        $chosen = $openIndices[0];

        foreach ($openIndices as $index) {
            if (\count($groups[$index]) < \count($groups[$chosen])) {
                $chosen = $index;
            }
        }

        return $chosen;
    }
}
