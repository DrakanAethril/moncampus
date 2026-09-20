<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The tree a picker modal browses: a library's folders, nested, each carrying the items filed in it.
 *
 * It is **not a fourth implementation of a tree**: the nesting is App\Service\FileLibraryTree's and
 * is delegated to it, exactly as App\Service\QuizFolderTree delegates the path arithmetic. What this
 * class adds is the two things a *picker* needs and a rail does not:
 *
 * - folders carry their **items**, because the rail lists folders alone (the listing is the other
 *   half of that screen) while a modal has no listing beside it;
 * - a branch that **leads to nothing is left out**. The modal exists to find something; an empty
 *   folder is a dead end one has to open to discover. A folder holding nothing itself is kept as
 *   long as a descendant holds something - it is the way to that descendant.
 *
 * Deliberately on primitives rather than on entities: the quiz library hands over QuizTemplates and
 * the file library will hand over FileLibraryNodes, and neither has anything to say about nesting.
 */
class LibraryPickerTree
{
    public function __construct(private readonly FileLibraryTree $paths)
    {
    }

    /**
     * @param list<array{id: int, parentId: int|null, name: string}>                  $folders
     * @param list<array{id: int, folderId: int|null, label: string, count: int, ...}> $items
     *
     * @return array{folders: list<array<string, mixed>>, items: list<array<string, mixed>>}
     */
    public function build(array $folders, array $items): array
    {
        $known = [];
        foreach ($folders as $folder) {
            $known[$folder['id']] = true;
        }

        $byFolder = [];
        $rootItems = [];

        foreach ($items as $item) {
            $folderId = $item['folderId'];

            // A folder the caller did not hand over files its items at the root rather than losing
            // them: which folders are on offer is the caller's decision, and an item that vanishes
            // because of it would be a disappearance nothing announces.
            if (null === $folderId || !isset($known[$folderId])) {
                $rootItems[] = $item;

                continue;
            }

            $byFolder[$folderId][] = $item;
        }

        return [
            'folders' => $this->fill($this->paths->assemble($folders), $byFolder),
            'items' => $this->sorted($rootItems),
        ];
    }

    /**
     * @param list<array{id: int, parentId: int|null, name: string, children: list<array<string, mixed>>, ...}> $nodes
     * @param array<int, list<array<string, mixed>>>                                                           $byFolder
     *
     * @return list<array<string, mixed>>
     */
    private function fill(array $nodes, array $byFolder): array
    {
        $kept = [];

        foreach ($nodes as $node) {
            /** @var list<array{id: int, parentId: int|null, name: string, children: list<array<string, mixed>>}> $children */
            $children = $node['children'];
            $node['children'] = $this->fill($children, $byFolder);
            $node['items'] = $this->sorted($byFolder[$node['id']] ?? []);

            if ([] === $node['children'] && [] === $node['items']) {
                continue;
            }

            $kept[] = $node;
        }

        return $kept;
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    private function sorted(array $items): array
    {
        usort($items, static function (array $a, array $b): int {
            $left = \is_string($a['label'] ?? null) ? $a['label'] : '';
            $right = \is_string($b['label'] ?? null) ? $b['label'] : '';

            return mb_strtolower($left) <=> mb_strtolower($right);
        });

        return $items;
    }
}
