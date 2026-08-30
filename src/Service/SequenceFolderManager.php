<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SequenceFolder;
use App\Entity\SequenceTemplate;
use App\Entity\User;
use App\Repository\SequenceFolderRepository;
use App\Repository\SequenceTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Everything that changes the shape of a sequence library's classement: create a folder, rename it,
 * move it, file a séquence into one, delete a folder.
 *
 * App\Service\QuizFolderManager's rules, which are the ones a tree has wherever it is drawn:
 *
 * - **moving a folder rewrites the whole subtree's `path` and `depth`**, and must refuse a move into
 *   the folder's own descendant, which would detach the branch from the library;
 * - **deleting a folder never deletes a séquence.** Its content - sub-folders and séquences alike -
 *   is promoted one level up, because a SequenceTemplate is hard-deleted in this application and
 *   there is no corbeille to fish one out of.
 *
 * The one thing that is this library's own is `moveSequence()` giving the séquence the last position
 * of its new folder: séquences carry a manual order the teacher arranges by hand, and an arrival
 * keeping a position from somewhere else would land in the middle of it.
 *
 * Nothing here flushes. The caller owns its unit of work, as everywhere else in this application.
 */
class SequenceFolderManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SequenceFolderRepository $folders,
        private readonly SequenceTemplateRepository $sequences,
        private readonly SequenceFolderTree $tree,
    ) {
    }

    public function createFolder(User $owner, ?SequenceFolder $parent, string $name): SequenceFolder
    {
        $folder = new SequenceFolder($owner, $this->tree->uniqueName($name, $this->folders->siblingNames($owner, $parent)));
        $folder->setParent($parent);
        $folder->setPath($this->tree->pathFor($parent));
        $folder->setDepth($this->tree->depthOf($folder->getPath()));
        $folder->setCreatedBy($owner);

        $this->entityManager->persist($folder);

        return $folder;
    }

    public function rename(SequenceFolder $folder, string $name, User $by): void
    {
        $folder->setName($this->tree->uniqueName($name, $this->folders->siblingNames($folder->getOwner(), $folder->getParent(), $folder->getId())));
        $this->touch($folder, $by);
    }

    /**
     * Moves a folder under a new parent, rewriting its whole subtree.
     *
     * @return bool false when the move is refused - a folder into its own descendant, or into
     *              somebody else's library. Refused rather than thrown: the drag came from a
     *              browser, and the screen redraws where the folder still is
     */
    public function moveFolder(SequenceFolder $folder, ?SequenceFolder $newParent, User $by): bool
    {
        if (null !== $newParent) {
            if ($newParent->getOwner()->getId() !== $folder->getOwner()->getId()) {
                return false;
            }

            if ($newParent->getId() === $folder->getId() || $this->tree->isDescendantOf($newParent->getPath(), (int) $folder->getId())) {
                return false;
            }
        }

        $oldPrefix = $folder->childPath();
        $folder->setParent($newParent);
        $folder->setName($this->tree->uniqueName($folder->getName(), $this->folders->siblingNames($folder->getOwner(), $newParent, $folder->getId())));
        $folder->setPath($this->tree->pathFor($newParent));
        $folder->setDepth($this->tree->depthOf($folder->getPath()));
        $newPrefix = $folder->childPath();

        // The one operation here that touches more than one row. Done in PHP over the subtree rather
        // than as a raw UPDATE so the in-memory entities stay true - the screen is redrawn from them
        // in the same request.
        foreach ($this->folders->findSubtree($folder) as $descendant) {
            if ($descendant->getId() === $folder->getId()) {
                continue;
            }

            $descendant->setPath($this->tree->rewrittenPath($descendant->getPath(), $oldPrefix, $newPrefix));
            $descendant->setDepth($this->tree->depthOf($descendant->getPath()));
        }

        $this->touch($folder, $by);

        return true;
    }

    /**
     * Files a séquence into a folder, or back at the root when $folder is null.
     *
     * @return bool false when the folder belongs to another library. A séquence cannot be filed
     *              somewhere its own teacher does not read
     */
    public function moveSequence(SequenceTemplate $sequence, ?SequenceFolder $folder, User $by): bool
    {
        $teacher = $sequence->getTeacher();

        if (null === $teacher) {
            return false;
        }

        if (null !== $folder && $folder->getOwner()->getId() !== $teacher->getId()) {
            return false;
        }

        if ($sequence->getFolder()?->getId() !== $folder?->getId()) {
            // Last of its new folder. The manual order is folder-local, so a séquence keeping the
            // position it held elsewhere would land in the middle of an order arranged by hand.
            $sequence->setOrder($this->sequences->maxOrderIn($teacher, $folder) + 1);
        }

        $sequence->setFolder($folder);

        return true;
    }

    /**
     * Deletes a folder and **promotes its content one level up** - sub-folders and séquences alike.
     *
     * Only the direct children need moving: everything deeper keeps the same parent, and its path is
     * rewritten by the move of the branch it hangs from.
     */
    public function delete(SequenceFolder $folder, User $by): void
    {
        $parent = $folder->getParent();

        foreach ($this->folders->findChildren($folder->getOwner(), $folder) as $child) {
            $this->moveFolder($child, $parent, $by);
        }

        foreach ($this->sequences->findInFolder($folder->getOwner(), $folder) as $sequence) {
            $this->moveSequence($sequence, $parent, $by);
        }

        $this->entityManager->remove($folder);
    }

    private function touch(SequenceFolder $folder, User $by): void
    {
        $folder->setLastUpdatedBy($by);
        $folder->setLastUpdatedDate(new \DateTimeImmutable());
    }
}
