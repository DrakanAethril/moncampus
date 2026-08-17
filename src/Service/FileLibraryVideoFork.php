<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FileLibraryNode;
use App\Entity\VideoResourceFile;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one exception of the whole link model, and it is **deferred**
 * (design/validated/file-library.md, "The one exception: vidéo suivie").
 *
 * An earlier draft had `VideoResourceFile` take an independent copy at link time, on the grounds that
 * deleting a library file must not destroy a class's viewing statistics. **That reasoning is wrong
 * and is recorded here so it is not re-derived**: the statistics are rows of `video_watch_progress`
 * keyed on `(file_id, student_id)`, and the statistics screen iterates `$resource->getFiles()`. The
 * S3 object takes no part in reading them. Cue points are keyed on `file_id` too. Delete the object
 * and the numbers are all still there.
 *
 * What actually breaks is narrower, and only one half of it is specific to video:
 *
 * - **playback**, so a « à visionner » still open becomes impossible to finish - which is exactly
 *   what happens to a « à lire » whose PDF is deleted, and the generic deletion modal answers it;
 * - **`Remplacer`**, which *is* specific: a replaced video makes the tracking false rather than
 *   missing. 62 % of another cut is not 62 %, and a cue point at 04:32 no longer points at anything.
 *   Nothing else in the platform measures a support from the inside.
 *
 * So the copy is kept but forked only at the moment the reference is about to become false:
 *
 * | Event on the library node | What happens to a vidéo suivie built on it |
 * |---|---|
 * | nothing | one object, shared, charged once to the quota |
 * | *Remplacer* | the previous object is **kept** instead of deleted, and the work's row now points at it |
 * | *Supprimer* | the object is copied to a key owned by the work, then the original is deleted |
 *
 * The forked object is **not charged to the quota**: it is a pedagogical artefact of the work, not a
 * file the teacher stores - and charging a teacher for a file they just deleted would be
 * indefensible. `storage_key` on `video_resource_file` therefore stays non-null in every case, so
 * there is no "media deleted" state to build in the player, the statistics screen or the mobile API.
 */
class FileLibraryVideoFork
{
    /** Where a forked copy lives: owned by the work, outside the library's own prefix. */
    public const string PREFIX = 'video-resources/library-forks/';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FileUploadService $fileUploads,
        private readonly ObjectStore $objectStore,
    ) {
    }

    /**
     * Called **before** a node's object is replaced.
     *
     * Nothing is copied: the object that is about to stop being the library's is simply kept, and the
     * work's row goes on pointing at it. Students stay measured against the cut they watched, and the
     * library serves the new one everywhere else.
     *
     * @return list<string> the keys that must not be deleted after all
     */
    public function keepPreviousObjectFor(FileLibraryNode $node): array
    {
        $kept = [];

        foreach ($this->filesOf($node) as $file) {
            // The row already carries the key; what changes is that it stops being a link. From here
            // on the work owns this object, so the library must not schedule its removal.
            $file->setLibraryNode(null);
            $kept[] = $file->getStorageKey();
        }

        return $kept;
    }

    /**
     * Called **before** a node is deleted.
     *
     * The object is copied to a key the work owns, and the row moves to it - so the original may then
     * go through the ordinary deferred deletion without taking the class's viewing with it.
     */
    public function forkBeforeDeleting(FileLibraryNode $node): void
    {
        $sourceKey = $node->getStorageKey();

        if (null === $sourceKey) {
            return;
        }

        foreach ($this->filesOf($node) as $file) {
            $forkKey = self::PREFIX.bin2hex(random_bytes(16)).'.'.$node->getExtension();

            // A server-side copy: nothing round-trips through PHP, and a 180 Mo video costs one S3
            // call rather than a transfer.
            $this->fileUploads->copy($sourceKey, $forkKey);
            // The copy must survive whatever happens to the original - including a purge that has
            // been brought forward by « Supprimer définitivement ».
            $this->objectStore->cancelDeletion($forkKey);

            $file->setStorageKey($forkKey);
            $file->setLibraryNode(null);
        }

        $this->entityManager->flush();
    }

    /** Whether any vidéo suivie is built on this node - what the deletion modal adds a line for. */
    public function isUsedByAVideo(FileLibraryNode $node): bool
    {
        return [] !== $this->filesOf($node);
    }

    /** @return list<VideoResourceFile> */
    private function filesOf(FileLibraryNode $node): array
    {
        /** @var list<VideoResourceFile> $files */
        $files = $this->entityManager->getRepository(VideoResourceFile::class)->findBy(['libraryNode' => $node]);

        return $files;
    }
}
