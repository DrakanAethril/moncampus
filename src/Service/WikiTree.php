<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The shape of a wiki: slugs, the materialized path, and the assembly the rail renders from.
 *
 * **Slug uniqueness lives here, not in an index.** The natural constraint would be
 * UNIQUE (wiki_id, parent_id, slug), and it does not work: MySQL treats every NULL as distinct, so
 * it enforces nothing at all for root-level nodes - exactly the level a user notices first. The
 * index on those columns stays a plain lookup index and uniqueSlug() is the authority.
 *
 * **`path` holds the ancestors' ids** ('/12/48/93/' for a node sitting under 12 > 48 > 93). It
 * answers "every descendant of 48" with one LIKE and builds a breadcrumb without walking parent by
 * parent. It is deliberately *not* the sort key: ordering is by `position` among siblings, and
 * positions change on every drag, so the rail loads the whole wiki in one query and assemble()
 * nests it in PHP. A wiki holds hundreds of nodes, not millions - a recursive CTE would buy nothing
 * and cost a MySQL-version dependency.
 */
class WikiTree
{
    /**
     * A title that folds to nothing at all ("???", "..."): the slug still has to exist, since it
     * is what a page's URL is read back from.
     */
    private const string FALLBACK_SLUG = 'page';

    /**
     * App\Service\HelpSlug is a generic ASCII slugger that happens to predate the wiki - shared
     * rather than copied, so the fold table cannot drift between two features that both put a
     * French title into a URL.
     */
    public function __construct(private readonly HelpSlug $slug)
    {
    }

    /**
     * @param list<string> $takenSlugs the slugs already used among the node's siblings
     */
    public function uniqueSlug(string $title, array $takenSlugs): string
    {
        $base = $this->slug->from($title);

        if ('' === $base) {
            $base = self::FALLBACK_SLUG;
        }

        if (!\in_array($base, $takenSlugs, true)) {
            return $base;
        }

        $suffix = 2;

        while (\in_array($base.'-'.$suffix, $takenSlugs, true)) {
            ++$suffix;
        }

        return $base.'-'.$suffix;
    }

    /** A node hanging straight off the wiki has no ancestor at all. */
    public function rootPath(): string
    {
        return '/';
    }

    public function childPath(string $parentPath, int $parentId): string
    {
        return $parentPath.$parentId.'/';
    }

    /**
     * The LIKE pattern matching every strict descendant of a node - the single UPDATE a subtree
     * move runs, and the only operation in the feature that touches more than one row.
     */
    public function descendantPathPattern(string $nodePath, int $nodeId): string
    {
        return $this->childPath($nodePath, $nodeId).'%';
    }

    /**
     * Re-prefixes a descendant's path after its ancestor moved. Anything not under the old prefix
     * is returned untouched, so the caller may run it over a wider row set without harm.
     */
    public function rewrittenPath(string $path, string $oldPrefix, string $newPrefix): string
    {
        if (!str_starts_with($path, $oldPrefix)) {
            return $path;
        }

        return $newPrefix.substr($path, \strlen($oldPrefix));
    }

    /**
     * Is $candidatePath inside the subtree of $nodeId - the node itself included?
     *
     * Guards the one move that would detach a branch from its wiki: dropping a folder onto one of
     * its own descendants. The surrounding slashes are what make this an exact ancestor test
     * rather than a substring match ('/12/480/' is not under 48).
     */
    public function isDescendantOf(string $candidatePath, int $nodeId): bool
    {
        return str_contains($candidatePath, '/'.$nodeId.'/');
    }

    public function depthOf(string $path): int
    {
        return substr_count($path, '/') - 1;
    }

    /**
     * @param list<int> $siblingPositions
     */
    public function nextPosition(array $siblingPositions): int
    {
        return [] === $siblingPositions ? 1 : max($siblingPositions) + 1;
    }

    /**
     * Nests a flat row set by parent and orders each sibling list by position.
     *
     * A row whose parent is absent from the set is dropped rather than surfaced at root level: it
     * would otherwise read as a top-level page it is not - which is what happens the moment the
     * caller filters out the trashed nodes and a deleted folder still has live children.
     *
     * @param list<array{id: int, parentId: int|null, position: int, ...}> $rows
     *
     * @return list<array{id: int, parentId: int|null, position: int, children: list<array<string, mixed>>, ...}>
     */
    public function assemble(array $rows): array
    {
        $byParent = [];
        $known = [];

        foreach ($rows as $row) {
            $known[$row['id']] = true;
        }

        foreach ($rows as $row) {
            $parentId = $row['parentId'];

            if (null !== $parentId && !isset($known[$parentId])) {
                continue;
            }

            $byParent[$parentId ?? 0][] = $row;
        }

        foreach ($byParent as $parentId => $children) {
            usort($children, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);
            $byParent[$parentId] = $children;
        }

        return $this->nest($byParent, 0);
    }

    /**
     * @param array<int, list<array{id: int, parentId: int|null, position: int, ...}>> $byParent
     *
     * @return list<array{id: int, parentId: int|null, position: int, children: list<array<string, mixed>>, ...}>
     */
    private function nest(array $byParent, int $parentId): array
    {
        $branch = [];

        foreach ($byParent[$parentId] ?? [] as $row) {
            $row['children'] = $this->nest($byParent, $row['id']);
            $branch[] = $row;
        }

        return $branch;
    }
}
