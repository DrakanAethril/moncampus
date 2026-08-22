<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FileLibraryNode;
use App\Repository\FileLibraryNodeRepository;

/**
 * A folder's whole content, flattened into the order somebody reads it in: depth first, folders
 * before files, alphabetical inside each level, with the depth carried so the screen can indent.
 *
 * It exists because **a shared folder is read on a screen of its own**, never in its owner's library
 * (App\Security\Voter\FileLibraryVoter says nobody browses somebody else's library, and the rail
 * would hand over every other folder they own). Two features open such a screen - a folder shared to
 * a colleague, and a folder put at a class's disposal - and they must list it the same way.
 *
 * The ordering is the reason this is not a `usort` at each call site. Sorting the subtree by its
 * materialised path looks right and is not: the path runs on identifiers, so under a folder holding
 * « Archives » (id 12) and « Bilans » (id 7), the children of Bilans come out before those of
 * Archives - a tree whose branches are interleaved. Walking the levels is what puts each folder's
 * content directly under it.
 *
 * @phpstan-type FileLibrarySubtreeRow array{node: FileLibraryNode, depth: int}
 */
class FileLibrarySubtree
{
    public function __construct(private readonly FileLibraryNodeRepository $nodes)
    {
    }

    /**
     * @return list<FileLibrarySubtreeRow>
     *
     * @phpstan-return list<FileLibrarySubtreeRow>
     */
    public function rows(FileLibraryNode $root): array
    {
        /** @var array<int, list<FileLibraryNode>> $byParent */
        $byParent = [];

        foreach ($this->nodes->findSubtree($root) as $member) {
            // A trashed node takes its whole subtree with it, so skipping it here skips its children
            // too - they carry their own deletedAt, set by the same operation.
            if ($member->getId() === $root->getId() || $member->isDeleted()) {
                continue;
            }

            $byParent[(int) $member->getParent()?->getId()][] = $member;
        }

        return $this->walk($byParent, (int) $root->getId(), 0);
    }

    /**
     * @param array<int, list<FileLibraryNode>> $byParent
     *
     * @return list<FileLibrarySubtreeRow>
     */
    private function walk(array $byParent, int $parentId, int $depth): array
    {
        $level = $byParent[$parentId] ?? [];

        // Folders before files whatever else is true, the same rule the library's own listing
        // follows: a folder is a place and a file is a thing.
        usort($level, static fn (FileLibraryNode $a, FileLibraryNode $b): int => [$a->isFile(), mb_strtolower($a->getName())]
            <=> [$b->isFile(), mb_strtolower($b->getName())]);

        $rows = [];

        foreach ($level as $node) {
            $rows[] = ['node' => $node, 'depth' => $depth];

            if ($node->isFolder()) {
                $rows = array_merge($rows, $this->walk($byParent, (int) $node->getId(), $depth + 1));
            }
        }

        return $rows;
    }
}
