<?php

declare(strict_types=1);

namespace App\Controller\FileLibrary;

use App\Entity\FileLibraryNode;
use App\Entity\User;
use App\Repository\FileLibraryNodeRepository;
use App\Security\Voter\FileLibraryVoter;
use App\Service\FileLibraryQuota;
use App\Service\FileLibraryTree;

/**
 * The handful of things every screen of the file library needs: who is looking, the node they named,
 * the rail, the breadcrumb, and the quota bar's numbers.
 *
 * Same shape as App\Controller\Wiki\WikiTrait and App\Controller\Settings\SettingsTabTrait - a trait
 * rather than a base class, because these controllers already extend AbstractController and what
 * they share is a set of helpers, not a hierarchy.
 */
trait FileLibraryTrait
{
    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    /**
     * The node named in the URL, or null for the root folder.
     *
     * **The Voter is asked here and nowhere else**, so a node belonging to somebody else answers 403
     * rather than rendering - and a node id that does not exist answers 404, which are two different
     * things a reader is entitled to be told apart.
     */
    private function loadNode(FileLibraryNodeRepository $nodes, ?int $nodeId, string $attribute = FileLibraryVoter::VIEW): ?FileLibraryNode
    {
        if (null === $nodeId) {
            $this->denyAccessUnlessGranted($attribute);

            return null;
        }

        $node = $nodes->find($nodeId) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted($attribute, $node);

        return $node;
    }

    /**
     * The rail: folders only, nested (design/validated/file-library.md, "The rail is the wiki's
     * rail"). Files are the table's business - a rail listing both would be the same list twice.
     *
     * @return list<array{id: int, parentId: int|null, name: string, path: string, children: list<array<string, mixed>>}>
     */
    private function railTree(FileLibraryNodeRepository $nodes, FileLibraryTree $tree, User $owner): array
    {
        $rows = array_map(
            static fn (FileLibraryNode $folder): array => [
                'id' => (int) $folder->getId(),
                'parentId' => $folder->getParent()?->getId(),
                'name' => $folder->getName(),
                'path' => $folder->getPath(),
            ],
            $nodes->findFolders($owner),
        );

        return $tree->assemble($rows);
    }

    /**
     * The ancestors of a folder, root first - the breadcrumb's middle segments, read off the
     * materialized path rather than by walking the parent chain (one query, whatever the depth).
     *
     * @return list<FileLibraryNode>
     */
    private function ancestorsOf(FileLibraryNodeRepository $nodes, ?FileLibraryNode $node): array
    {
        if (null === $node) {
            return [];
        }

        $ids = array_values(array_filter(array_map(intval(...), explode('/', trim($node->getPath(), '/')))));

        if ([] === $ids) {
            return [];
        }

        $byId = [];

        foreach ($nodes->findBy(['id' => $ids]) as $ancestor) {
            $byId[(int) $ancestor->getId()] = $ancestor;
        }

        $ancestors = [];

        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ancestors[] = $byId[$id];
            }
        }

        return $ancestors;
    }

    /**
     * What the bar at the top of the rail draws - `312 Mo / 1 Go · 31 %`, and its colour.
     *
     * @return array{used: int, limit: int, percent: int, level: string, usedLabel: string, limitLabel: string}
     */
    private function quotaBar(FileLibraryQuota $quota, User $owner): array
    {
        return [
            'used' => $quota->usedBytes($owner),
            'limit' => $quota->limitFor($owner),
            'percent' => $quota->usedPercent($owner),
            'level' => $quota->level($owner),
            'usedLabel' => \App\Service\ByteSize::format($quota->usedBytes($owner)),
            'limitLabel' => \App\Service\ByteSize::format($quota->limitFor($owner)),
        ];
    }
}
