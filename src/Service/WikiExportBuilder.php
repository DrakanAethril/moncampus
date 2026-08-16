<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Wiki;
use App\Entity\WikiNode;
use App\Repository\WikiNodeRepository;

/**
 * Flattens a wiki - or one branch of it - into the depth-first list of pages the PDF prints, each
 * carrying the level its headings have been shifted to.
 *
 * Depth-first because that is the order a reader would click through the rail, and because the
 * table of contents has to number itself the same way. The heading shift is applied here rather
 * than in the template so that the printed body and the entry in the table of contents are built
 * from one decision instead of two that can disagree.
 */
class WikiExportBuilder
{
    /**
     * @phpstan-type ExportedPage array{node: WikiNode, level: int, depth: int, anchor: string, body: string}
     */
    public function __construct(
        private readonly WikiNodeRepository $nodes,
        private readonly WikiTree $tree,
        private readonly WikiHeadingShift $shift,
        private readonly WikiPageOutline $outline,
    ) {
    }

    /**
     * @param ?WikiNode $root the subtree to export - null for the whole wiki
     *
     * @return list<array{node: WikiNode, level: int, depth: int, anchor: string, body: string}>
     */
    public function pages(Wiki $wiki, ?WikiNode $root = null, bool $singlePage = false): array
    {
        if ($singlePage && null !== $root) {
            return [$this->page($root, 0)];
        }

        $rows = [];

        foreach ($this->nodes->findLiveOf($wiki) as $node) {
            $id = $node->getId();

            if (null === $id) {
                continue;
            }

            $rows[] = [
                'id' => $id,
                'parentId' => $node->getParent()?->getId(),
                'position' => $node->getPosition(),
                'node' => $node,
            ];
        }

        $branch = $this->tree->assemble($rows);

        if (null !== $root) {
            $branch = $this->locate($branch, $root->getId());
        }

        $pages = [];
        $this->walk($branch, 0, $pages);

        return $pages;
    }

    /**
     * @param list<array<string, mixed>>                                                        $branch
     * @param list<array{node: WikiNode, level: int, depth: int, anchor: string, body: string}> $pages
     *
     * @param-out list<array{node: WikiNode, level: int, depth: int, anchor: string, body: string}> $pages
     */
    private function walk(array $branch, int $depth, array &$pages): void
    {
        foreach ($branch as $row) {
            /** @var WikiNode $node */
            $node = $row['node'];
            $pages[] = $this->page($node, $depth);

            /** @var list<array<string, mixed>> $children */
            $children = $row['children'];
            $this->walk($children, $depth + 1, $pages);
        }
    }

    /**
     * @return array{node: WikiNode, level: int, depth: int, anchor: string, body: string}
     */
    private function page(WikiNode $node, int $depth): array
    {
        // The outline runs first so the headings carry their anchors, then the shift demotes them -
        // in that order, because the shift rewrites the tag name and would otherwise be undone.
        $stamped = $this->outline->build($node->getBody());
        $body = '' === $stamped['html'] ? ($node->getBody() ?? '') : $stamped['html'];

        return [
            'node' => $node,
            'depth' => $depth,
            'level' => $this->shift->titleLevel($depth),
            'anchor' => 'wiki-page-'.$node->getId(),
            'body' => $this->shift->apply($body, $depth),
        ];
    }

    /**
     * The sub-branch rooted at $nodeId, so `?node=` can narrow the export without re-querying.
     *
     * @param list<array<string, mixed>> $branch
     *
     * @return list<array<string, mixed>>
     */
    private function locate(array $branch, ?int $nodeId): array
    {
        if (null === $nodeId) {
            return $branch;
        }

        foreach ($branch as $row) {
            /** @var WikiNode $node */
            $node = $row['node'];

            if ($node->getId() === $nodeId) {
                return [$row];
            }

            /** @var list<array<string, mixed>> $children */
            $children = $row['children'];
            $found = $this->locate($children, $nodeId);

            if ([] !== $found) {
                return $found;
            }
        }

        return [];
    }
}
