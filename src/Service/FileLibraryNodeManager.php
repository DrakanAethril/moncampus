<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FileLibraryNode;
use App\Entity\User;
use App\Enum\FileLibraryNodeType;
use App\Repository\FileLibraryNodeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Everything that changes the shape of a library: create, rename, move, replace, delete, restore.
 *
 * It exists for the repository's own rule - business rules live in `src/Service/`, not in the
 * controller - and because three of these operations have a rule that is easy to get subtly wrong:
 *
 * - **moving a folder rewrites the whole subtree's `path` and `depth`**, and must refuse a move into
 *   the folder's own descendant (which would detach the branch from the library);
 * - **replacing keeps the node id**, which is what makes every link to that file keep pointing at
 *   the right thing - the single most requested behaviour of a file library, and free here;
 * - **deleting is deferred** (design/validated/object-deletion.md): the row is marked, the bytes
 *   follow thirty days later, and the file stops counting against the quota at once.
 *
 * Nothing here flushes. The caller owns its unit of work, as everywhere else in this application.
 */
class FileLibraryNodeManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FileLibraryNodeRepository $nodes,
        private readonly FileLibraryTree $tree,
        private readonly ObjectStore $objectStore,
    ) {
    }

    /** Where the library's objects live in the bucket. */
    public const string UPLOAD_PREFIX = 'file-library/';

    public function createFolder(User $owner, ?FileLibraryNode $parent, string $name): FileLibraryNode
    {
        $folder = new FileLibraryNode($owner, FileLibraryNodeType::Folder, $this->tree->uniqueName($name, $this->nodes->siblingNames($owner, $parent)));

        return $this->place($folder, $parent, $owner);
    }

    /**
     * The row of a file whose object is already in the bucket - the upload endpoint has claimed the
     * staged upload into the library's prefix before getting here.
     */
    public function createFile(
        User $owner,
        ?FileLibraryNode $parent,
        string $name,
        string $storageKey,
        string $originalName,
        string $mimeType,
        int $sizeBytes,
        ?int $durationSeconds = null,
    ): FileLibraryNode {
        $file = new FileLibraryNode($owner, FileLibraryNodeType::File, $this->tree->uniqueName($name, $this->nodes->siblingNames($owner, $parent)));
        $file
            ->setStorageKey($storageKey)
            ->setOriginalName($originalName)
            ->setMimeType('' === $mimeType ? null : $mimeType)
            ->setSizeBytes($sizeBytes)
            ->setDurationSeconds($durationSeconds);

        return $this->place($file, $parent, $owner);
    }

    public function rename(FileLibraryNode $node, string $name, User $by): void
    {
        $node->setName($this->tree->uniqueName($name, $this->nodes->siblingNames($node->getOwner(), $node->getParent(), $node->getId())));
        $this->touch($node, $by);
    }

    /**
     * Moves a node under a new parent, rewriting its whole subtree.
     *
     * @return bool false when the move is refused - a folder into its own descendant, or into
     *              somebody else's library. Refusing rather than throwing: the drag came from a
     *              browser, and the screen redraws where the node still is
     */
    public function move(FileLibraryNode $node, ?FileLibraryNode $newParent, User $by): bool
    {
        if (null !== $newParent) {
            if ($newParent->getOwner()->getId() !== $node->getOwner()->getId() || !$newParent->isFolder() || $newParent->isDeleted()) {
                return false;
            }

            // The move that would detach a branch from the library: a folder dropped onto one of its
            // own descendants, or onto itself.
            if ($newParent->getId() === $node->getId() || $this->tree->isDescendantOf($newParent->getPath(), (int) $node->getId())) {
                return false;
            }
        }

        $oldPrefix = $this->tree->childPath($node->getPath(), (int) $node->getId());
        $node->setParent($newParent);
        $node->setName($this->tree->uniqueName($node->getName(), $this->nodes->siblingNames($node->getOwner(), $newParent, $node->getId())));
        $node->setPath($this->tree->pathFor($newParent));
        $node->setDepth($this->tree->depthOf($node->getPath()));
        $newPrefix = $this->tree->childPath($node->getPath(), (int) $node->getId());

        // The one operation in this feature that touches more than one row. Done in PHP over the
        // subtree rather than as a raw UPDATE so the in-memory entities stay true - the screen is
        // redrawn from them in the same request.
        foreach ($this->nodes->findSubtree($node) as $descendant) {
            if ($descendant->getId() === $node->getId()) {
                continue;
            }

            $descendant->setPath($this->tree->rewrittenPath($descendant->getPath(), $oldPrefix, $newPrefix));
            $descendant->setDepth($this->tree->depthOf($descendant->getPath()));
        }

        $this->touch($node, $by);

        return true;
    }

    /**
     * *Remplacer*: a new object under the same node.
     *
     * **The node id does not change**, so every link keeps pointing at the right thing and the
     * corrected PDF is the one the eleven assignments now serve. The old object goes through the
     * ordinary deferred deletion, so a replacement made by mistake is recoverable from the bucket
     * for the retention window like everything else.
     */
    public function replace(FileLibraryNode $node, string $storageKey, string $originalName, string $mimeType, int $sizeBytes, User $by): void
    {
        $previousKey = $node->getStorageKey();

        $node
            ->setStorageKey($storageKey)
            ->setOriginalName($originalName)
            ->setMimeType('' === $mimeType ? null : $mimeType)
            ->setSizeBytes($sizeBytes);

        if (null !== $previousKey) {
            $this->objectStore->scheduleDeletion($previousKey, 'file-library');
        }

        $this->touch($node, $by);
    }

    /**
     * The corbeille. Marks the node and, for a folder, everything under it: the bytes stay for the
     * retention window, and the files stop counting against the quota at once.
     *
     * @return list<FileLibraryNode> what was marked - the caller reports the count
     */
    public function trash(FileLibraryNode $node, User $by): array
    {
        $now = new \DateTimeImmutable();
        $marked = [];

        foreach ($this->nodes->findSubtree($node) as $member) {
            if ($member->isDeleted()) {
                continue;
            }

            $member->setDeletedAt($now);

            // The object leaves the bucket in thirty days, not now: App\Service\ObjectStore is what
            // decides when, and this only says the file is gone from the teacher's point of view.
            if ($member->isFile() && null !== $member->getStorageKey()) {
                $this->objectStore->scheduleDeletion($member->getStorageKey(), 'file-library');
            }

            $marked[] = $member;
        }

        $this->touch($node, $by);

        return $marked;
    }

    /**
     * *Restaurer*: the file comes back into its folder, **and nothing else**.
     *
     * « Supprimer partout » removed the attachment rows, and a restore does not put them back - the
     * screen says so plainly rather than implying otherwise (design/validated/object-deletion.md).
     * The pending removal of the bytes is cancelled here, which is the half that makes the window
     * real: without it the object would still go on schedule and the restored row would point at
     * nothing.
     *
     * A file whose folder is itself deleted comes back **at the root**: restoring into a folder that
     * is in the corbeille would put it somewhere the teacher cannot see.
     */
    public function restore(FileLibraryNode $node, User $by): void
    {
        $parent = $node->getParent();

        if (null !== $parent && $parent->isDeleted()) {
            $node->setParent(null);
            $node->setPath($this->tree->rootPath());
            $node->setDepth(0);
        }

        $node->setName($this->tree->uniqueName($node->getName(), $this->nodes->siblingNames($node->getOwner(), $node->getParent(), $node->getId())));

        foreach ($this->nodes->findSubtree($node) as $member) {
            if (!$member->isDeleted()) {
                continue;
            }

            $member->setDeletedAt(null);

            if ($member->isFile() && null !== $member->getStorageKey()) {
                $this->objectStore->cancelDeletion($member->getStorageKey());
            }
        }

        $this->touch($node, $by);
    }

    /**
     * « Supprimer définitivement » - which does not delete inline.
     *
     * It back-dates the pending removal so the next purge takes it, because **one path removes
     * bytes** and it is App\Command\PurgeUploadsCommand's. The row goes from the corbeille at once,
     * which is what the teacher asked for.
     */
    public function purgeNow(FileLibraryNode $node): void
    {
        foreach ($this->nodes->findSubtree($node) as $member) {
            if ($member->isFile() && null !== $member->getStorageKey()) {
                $this->objectStore->expediteDeletion($member->getStorageKey());
            }

            $this->entityManager->remove($member);
        }
    }

    private function place(FileLibraryNode $node, ?FileLibraryNode $parent, User $by): FileLibraryNode
    {
        $node->setParent($parent);
        $node->setPath($this->tree->pathFor($parent));
        $node->setDepth($this->tree->depthOf($node->getPath()));
        $node->setCreatedBy($by);

        $this->entityManager->persist($node);

        return $node;
    }

    private function touch(FileLibraryNode $node, User $by): void
    {
        $node->setLastUpdatedBy($by);
        $node->setLastUpdatedDate(new \DateTimeImmutable());
    }
}
