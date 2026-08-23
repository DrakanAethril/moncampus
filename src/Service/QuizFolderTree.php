<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizFolder;

/**
 * The materialized path of the quiz library's folders.
 *
 * It is **not a third implementation of a tree**: the path arithmetic is App\Service\FileLibraryTree's
 * and is delegated to it, because '/12/48/' means exactly the same thing here. What this class adds
 * is the two things that are the quiz library's own:
 *
 * - `pathFor()`, which takes a QuizFolder rather than a FileLibraryNode;
 * - **its own `uniqueName()`**, without the extension rule. The file library puts the suffix before
 *   the extension because "cours.pdf (2)" opens in nothing; a folder named « 3.5 Réseaux » has no
 *   extension, and that rule would rename it « 3 (2).5 Réseaux ».
 */
class QuizFolderTree
{
    public function __construct(private readonly FileLibraryTree $paths)
    {
    }

    public function rootPath(): string
    {
        return $this->paths->rootPath();
    }

    public function childPath(string $parentPath, int $parentId): string
    {
        return $this->paths->childPath($parentPath, $parentId);
    }

    public function pathFor(?QuizFolder $parent): string
    {
        return null === $parent ? $this->rootPath() : $parent->childPath();
    }

    public function depthOf(string $path): int
    {
        return $this->paths->depthOf($path);
    }

    public function rewrittenPath(string $path, string $oldPrefix, string $newPrefix): string
    {
        return $this->paths->rewrittenPath($path, $oldPrefix, $newPrefix);
    }

    /**
     * Is this path inside the subtree of $folderId, the folder itself included? Guards the one move
     * that would detach a branch: a folder dropped onto one of its own descendants.
     */
    public function isDescendantOf(string $candidatePath, int $folderId): bool
    {
        return $this->paths->isDescendantOf($candidatePath, $folderId);
    }

    /**
     * Nests a flat row set by parent, each sibling list ordered by name.
     *
     * @param list<array{id: int, parentId: int|null, name: string, ...}> $rows
     *
     * @return list<array{id: int, parentId: int|null, name: string, children: list<array<string, mixed>>, ...}>
     */
    public function assemble(array $rows): array
    {
        return $this->paths->assemble($rows);
    }

    /**
     * A name no sibling folder already holds - « Nouveau dossier », then « Nouveau dossier (2) ».
     *
     * @param list<string> $takenNames the names already used among the folder's siblings
     */
    public function uniqueName(string $name, array $takenNames): string
    {
        $taken = array_map(mb_strtolower(...), $takenNames);

        if (!\in_array(mb_strtolower($name), $taken, true)) {
            return $name;
        }

        $suffix = 2;

        while (\in_array(mb_strtolower(\sprintf('%s (%d)', $name, $suffix)), $taken, true)) {
            ++$suffix;
        }

        return \sprintf('%s (%d)', $name, $suffix);
    }
}
