<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Turning one saved set of groups (App\Entity\GroupBatch) into the list of wikis it should become -
 * one wiki per group, holding that group's students.
 *
 * Pure on purpose: ids and strings only, no entity and no database, so the two rules that are easy
 * to get wrong are testable on their own.
 *
 * 1. **An empty group gets no wiki.** A set may carry a group everybody has been dragged out of;
 *    creating a members-less wiki for it would produce a shared wiki nobody but its creator can
 *    reach, which is not what "un wiki par groupe" means.
 * 2. **The numbering follows the position in the set, not the rank among the wikis created.** The
 *    teacher reads "Groupe 3" on the group-creation screen; if group 2 happens to be empty, the
 *    third group must still become "Groupe 3" here, or the two screens stop naming the same thing.
 */
class GroupWikiPlanner
{
    /**
     * @param list<list<int>> $groups             student ids, one inner list per group, in group order
     * @param string          $groupTitleTemplate carries %n%, e.g. "Groupe %n%"
     *
     * @return list<array{title: string, memberIds: list<int>}>
     */
    public function plan(array $groups, string $titlePrefix, string $groupTitleTemplate): array
    {
        $plan = [];

        foreach ($groups as $index => $memberIds) {
            $memberIds = array_values(array_unique(array_filter($memberIds, static fn (int $id): bool => $id > 0)));

            if ([] === $memberIds) {
                continue;
            }

            $groupTitle = str_replace('%n%', (string) ($index + 1), $groupTitleTemplate);
            $prefix = trim($titlePrefix);

            $plan[] = [
                'title' => '' === $prefix ? $groupTitle : \sprintf('%s — %s', $prefix, $groupTitle),
                'memberIds' => $memberIds,
            ];
        }

        return $plan;
    }
}
