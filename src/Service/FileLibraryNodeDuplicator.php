<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FileLibraryNode;
use App\Entity\User;
use App\Repository\FileLibraryNodeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Dupliquer chez moi » for a shared file or folder
 * (design/validated/content-sharing-between-teachers.md).
 *
 * A folder shares its subtree, and the duplication recreates that subtree under a destination folder
 * the recipient picks. **Every file gets a real second S3 object**: `file_library_node.storage_key`
 * is UNIQUE, and more to the point « un lien est une référence » is a rule *inside one library* -
 * a reference across two would mean the author's deletion silently emptying a colleague's folder.
 *
 * The quota is asked **once, with the sum**, before anything is written, and the refusal is global.
 * Asking per file is exactly how a partial write happens, and a partial write looks like a success.
 *
 * Deleted nodes are not copied: what the author put in their corbeille is not what they shared.
 */
class FileLibraryNodeDuplicator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FileLibraryNodeRepository $nodes,
        private readonly FileLibraryNodeManager $manager,
        private readonly FileLibraryQuota $quota,
        private readonly FileUploadService $fileUploadService,
    ) {
    }

    /**
     * What the copy will hold and what it will weigh - the confirmation screen's content, and the
     * one number the quota is asked about.
     *
     * @return array{fileCount: int, folderCount: int, totalBytes: int}
     */
    public function plan(FileLibraryNode $source): array
    {
        $fileCount = 0;
        $folderCount = 0;
        $totalBytes = 0;

        foreach ($this->liveSubtree($source) as $node) {
            if ($node->isFolder()) {
                ++$folderCount;

                continue;
            }

            ++$fileCount;
            $totalBytes += $node->getSizeBytes() ?? 0;
        }

        return ['fileCount' => $fileCount, 'folderCount' => $folderCount, 'totalBytes' => $totalBytes];
    }

    /**
     * @throws ContentShareQuotaException when it does not fit; nothing has been written
     */
    public function duplicate(FileLibraryNode $source, User $recipient, ?FileLibraryNode $destination): FileLibraryNode
    {
        $plan = $this->plan($source);

        if (!$this->quota->accepts($recipient, $plan['totalBytes'])) {
            throw new ContentShareQuotaException($plan['totalBytes'], $this->quota->remainingBytes($recipient));
        }

        // One transaction: a folder must be written before it can be a parent (its materialized path
        // is built from its parent's id), so the copy is several flushes and this is what makes them
        // one all-or-nothing gesture.
        return $this->entityManager->wrapInTransaction(function () use ($source, $recipient, $destination): FileLibraryNode {
            $subtree = $this->liveSubtree($source);
            $copies = [];

            // Shallowest first, so a node's parent has always been written - and flushed - before it.
            usort($subtree, static fn (FileLibraryNode $a, FileLibraryNode $b): int => $a->getDepth() <=> $b->getDepth() ?: (int) $a->getId() <=> (int) $b->getId());

            foreach ($subtree as $node) {
                $parentId = $node->getParent()?->getId();
                $parent = $node->getId() === $source->getId() ? $destination : ($copies[$parentId] ?? $destination);
                $copies[(int) $node->getId()] = $this->copyNode($node, $recipient, $parent);
                $this->entityManager->flush();
            }

            return $copies[(int) $source->getId()];
        });
    }

    private function copyNode(FileLibraryNode $node, User $recipient, ?FileLibraryNode $parent): FileLibraryNode
    {
        if ($node->isFolder()) {
            return $this->manager->createFolder($recipient, $parent, $node->getName());
        }

        $sourceKey = (string) $node->getStorageKey();
        $extension = pathinfo($sourceKey, \PATHINFO_EXTENSION);
        $newKey = FileLibraryNodeManager::UPLOAD_PREFIX.bin2hex(random_bytes(16)).('' !== $extension ? '.'.$extension : '');

        $this->fileUploadService->copy($sourceKey, $newKey);

        return $this->manager->createFile(
            $recipient,
            $parent,
            $node->getName(),
            $newKey,
            $node->getOriginalName() ?? $node->getName(),
            $node->getMimeType() ?? '',
            $node->getSizeBytes() ?? 0,
            $node->getDurationSeconds(),
        );
    }

    /**
     * The subtree minus its corbeille: what an author deleted is not what they shared.
     *
     * A live node under a deleted folder is dropped too - copying it would lift it out of a branch
     * its owner has removed.
     *
     * @return list<FileLibraryNode>
     */
    private function liveSubtree(FileLibraryNode $source): array
    {
        $live = [];
        $deletedIds = [];

        $subtree = $this->nodes->findSubtree($source);
        usort($subtree, static fn (FileLibraryNode $a, FileLibraryNode $b): int => $a->getDepth() <=> $b->getDepth() ?: (int) $a->getId() <=> (int) $b->getId());

        foreach ($subtree as $node) {
            $parentId = $node->getParent()?->getId();

            if ($node->isDeleted() || (null !== $parentId && isset($deletedIds[$parentId]))) {
                $deletedIds[(int) $node->getId()] = true;

                continue;
            }

            $live[] = $node;
        }

        return $live;
    }
}
