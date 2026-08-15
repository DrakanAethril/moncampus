<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The parent/child hierarchy of App\Entity\Group - "SIO2 est dans SIO, qui est dans Campus".
 *
 * Pure, over group ids alone: every method takes the parent link as a plain map
 * (id => parent id or null), so a screen that already holds its groups can answer "what is under
 * SIO?" or "is this user's group inside Campus?" without another query, and the rules stay testable
 * without building an entity graph. App\Repository\GroupRepository::findParentMap() is what turns
 * rows into that map.
 *
 * Every walk here is loop-safe. A cycle cannot be saved (App\Entity\Group::validateParent() calls
 * wouldCycle()), but one written before that check existed - or straight into the database - must
 * still render a screen rather than hang it.
 */
class GroupHierarchy
{
    /**
     * The chain up to the root, root first, excluding the group itself. A parent absent from the
     * map (deactivated, so filtered out of an actives-only listing) ends the walk.
     *
     * @param array<int, int|null> $parentById
     *
     * @return list<int>
     */
    public function ancestorIds(int $id, array $parentById): array
    {
        $ancestors = [];
        $seen = [$id => true];
        $current = $parentById[$id] ?? null;

        while (null !== $current && !isset($seen[$current]) && \array_key_exists($current, $parentById)) {
            $seen[$current] = true;
            $ancestors[] = $current;
            $current = $parentById[$current] ?? null;
        }

        return array_reverse($ancestors);
    }

    /**
     * Everything below the group, at every level, excluding the group itself.
     *
     * @param array<int, int|null> $parentById
     *
     * @return list<int>
     */
    public function descendantIds(int $id, array $parentById): array
    {
        $descendants = $this->branchIds($id, $parentById);
        array_shift($descendants);

        return $descendants;
    }

    /**
     * The group and everything below it - the shape a "show me everything under SIO" filter wants.
     *
     * @param array<int, int|null> $parentById
     *
     * @return list<int>
     */
    public function branchIds(int $id, array $parentById): array
    {
        $childrenByParent = $this->childrenByParent($parentById);

        $branch = [];
        $seen = [];
        $stack = [$id];

        while ([] !== $stack) {
            $current = array_pop($stack);

            if (isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;
            $branch[] = $current;

            // Pushed in reverse so the stack pops them back in the map's own order.
            foreach (array_reverse($childrenByParent[$current] ?? []) as $child) {
                $stack[] = $child;
            }
        }

        return $branch;
    }

    /**
     * Whether attaching $id to $parentId would close a loop - a group that is its own ancestor.
     * Refused at save time rather than survived at read time, the same reasoning as
     * App\Service\AccessConditionCycleDetector.
     *
     * @param array<int, int|null> $parentById the hierarchy as currently stored
     */
    public function wouldCycle(int $id, ?int $parentId, array $parentById): bool
    {
        if (null === $parentId) {
            return false;
        }

        if ($id === $parentId) {
            return true;
        }

        $seen = [];
        $current = $parentId;

        while (null !== $current) {
            if ($current === $id) {
                return true;
            }

            // A loop already stored further up is somebody else's problem, but it still has to be
            // walked out of, or this answers by hanging.
            if (isset($seen[$current])) {
                return false;
            }

            $seen[$current] = true;
            $current = $parentById[$current] ?? null;
        }

        return false;
    }

    /**
     * The rows re-ordered as a tree - each group immediately followed by its own branch - with the
     * indentation level a hierarchical listing needs. Sibling order is the caller's row order, so
     * the screen keeps whatever sort it asked the repository for.
     *
     * A row whose parent is not in the set (inactive parent, actives-only listing) is rendered as a
     * root, and so is one caught in a stored loop: dropping either would make groups disappear from
     * a screen with nothing saying why.
     *
     * @param list<array{id: int, parentId: int|null}> $rows
     *
     * @return list<array{id: int, depth: int}>
     */
    public function flatten(array $rows): array
    {
        $childrenByParent = [];
        $known = [];

        foreach ($rows as $row) {
            $known[$row['id']] = true;
        }

        foreach ($rows as $row) {
            $parentId = $row['parentId'];

            if (null !== $parentId && isset($known[$parentId])) {
                $childrenByParent[$parentId][] = $row['id'];
            }
        }

        $flattened = [];
        $visited = [];

        $walk = function (int $id, int $depth) use (&$walk, &$flattened, &$visited, $childrenByParent): void {
            if (isset($visited[$id])) {
                return;
            }

            $visited[$id] = true;
            $flattened[] = ['id' => $id, 'depth' => $depth];

            foreach ($childrenByParent[$id] ?? [] as $childId) {
                $walk($childId, $depth + 1);
            }
        };

        foreach ($rows as $row) {
            $parentId = $row['parentId'];

            if (null === $parentId || !isset($known[$parentId])) {
                $walk($row['id'], 0);
            }
        }

        // Whatever no root could reach: a loop, or a branch hanging off one.
        foreach ($rows as $row) {
            $walk($row['id'], 0);
        }

        return $flattened;
    }

    /**
     * @param array<int, int|null> $parentById
     *
     * @return array<int, list<int>>
     */
    private function childrenByParent(array $parentById): array
    {
        $childrenByParent = [];

        foreach ($parentById as $id => $parentId) {
            if (null !== $parentId) {
                $childrenByParent[$parentId][] = $id;
            }
        }

        return $childrenByParent;
    }
}
