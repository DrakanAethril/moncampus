<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FileLibraryNode;

/**
 * The materialized path of the file library's tree - the second tree in this application, and
 * deliberately not a second *implementation* of one (design/validated/file-library.md).
 *
 * It is App\Service\WikiTree's shape, minus what the library does not have:
 *
 * - **no slug**, because a library node has no URL of its own to be read back from - it is reached
 *   by id. What has to be unique among siblings is the **name**, which is what a reader types;
 * - **no position**, because the library sorts by name, date or size at the reader's choice. That
 *   also drops the reorder drag, leaving one gesture: move into a folder.
 *
 * `uniqueName()` is the authority on sibling uniqueness, and no index backs it up: MySQL treats
 * every NULL as distinct, so UNIQUE (owner, parent, name) would constrain nothing at root level -
 * the exact trap WikiTree::uniqueSlug() already documents. Note the library does **not** always
 * silently rename: re-uploading a name that exists asks the teacher (Remplacer / Conserver les
 * deux), and only the second answer comes back here.
 */
class FileLibraryTree
{
    /** A node hanging straight off the library has no ancestor at all. */
    public function rootPath(): string
    {
        return '/';
    }

    public function childPath(string $parentPath, int $parentId): string
    {
        return $parentPath.$parentId.'/';
    }

    public function pathFor(?FileLibraryNode $parent): string
    {
        return null === $parent ? $this->rootPath() : $this->childPath($parent->getPath(), (int) $parent->getId());
    }

    public function depthOf(string $path): int
    {
        return substr_count($path, '/') - 1;
    }

    /**
     * The LIKE pattern matching every strict descendant - the single UPDATE a subtree move runs, and
     * the only operation in this feature that touches more than one row.
     */
    public function descendantPathPattern(string $nodePath, int $nodeId): string
    {
        return $this->childPath($nodePath, $nodeId).'%';
    }

    /** Re-prefixes a descendant's path after its ancestor moved. */
    public function rewrittenPath(string $path, string $oldPrefix, string $newPrefix): string
    {
        if (!str_starts_with($path, $oldPrefix)) {
            return $path;
        }

        return $newPrefix.substr($path, \strlen($oldPrefix));
    }

    /**
     * Is this path inside the subtree of $nodeId, the node itself included?
     *
     * Guards the one move that would detach a branch from the library: dropping a folder onto one of
     * its own descendants. The surrounding slashes make it an exact ancestor test rather than a
     * substring match - '/12/480/' is not under 48.
     */
    public function isDescendantOf(string $candidatePath, int $nodeId): bool
    {
        return str_contains($candidatePath, '/'.$nodeId.'/');
    }

    /**
     * A name no sibling already holds - "cours.pdf", then "cours (2).pdf".
     *
     * The suffix goes **before the extension**, which is the only placement a file manager, a
     * download and a double-click all agree on: "cours.pdf (2)" opens in nothing.
     *
     * @param list<string> $takenNames the names already used among the node's siblings
     */
    public function uniqueName(string $name, array $takenNames): string
    {
        $taken = array_map(mb_strtolower(...), $takenNames);

        if (!\in_array(mb_strtolower($name), $taken, true)) {
            return $name;
        }

        $extension = pathinfo($name, \PATHINFO_EXTENSION);
        $base = '' === $extension ? $name : mb_substr($name, 0, -(mb_strlen($extension) + 1));
        $suffix = 2;

        while (\in_array(mb_strtolower($this->numbered($base, $extension, $suffix)), $taken, true)) {
            ++$suffix;
        }

        return $this->numbered($base, $extension, $suffix);
    }

    /**
     * Nests a flat row set by parent, each sibling list ordered by name.
     *
     * A row whose parent is absent from the set is dropped rather than surfaced at root level: it
     * would otherwise read as a top-level folder it is not, which is exactly what happens the moment
     * the caller filters out the deleted nodes and a deleted folder still has live children.
     *
     * @param list<array{id: int, parentId: int|null, name: string, ...}> $rows
     *
     * @return list<array{id: int, parentId: int|null, name: string, children: list<array<string, mixed>>, ...}>
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
            usort($children, static fn (array $a, array $b): int => mb_strtolower($a['name']) <=> mb_strtolower($b['name']));
            $byParent[$parentId] = $children;
        }

        return $this->nest($byParent, 0);
    }

    /**
     * @param array<int, list<array{id: int, parentId: int|null, name: string, ...}>> $byParent
     *
     * @return list<array{id: int, parentId: int|null, name: string, children: list<array<string, mixed>>, ...}>
     */
    private function nest(array $byParent, int $parentId): array
    {
        $nested = [];

        foreach ($byParent[$parentId] ?? [] as $row) {
            $row['children'] = $this->nest($byParent, $row['id']);
            $nested[] = $row;
        }

        return $nested;
    }

    private function numbered(string $base, string $extension, int $suffix): string
    {
        return '' === $extension
            ? \sprintf('%s (%d)', $base, $suffix)
            : \sprintf('%s (%d).%s', $base, $suffix, $extension);
    }
}
