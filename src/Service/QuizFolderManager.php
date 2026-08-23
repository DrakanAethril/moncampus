<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizFolder;
use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Repository\QuizFolderRepository;
use App\Repository\QuizTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Everything that changes the shape of a quiz library's classement: create a folder, rename it,
 * move it, move a quiz into one, delete a folder.
 *
 * Two of these carry a rule that is easy to get subtly wrong, and both are here rather than in a
 * controller for the repository's own reason - business rules live in `src/Service/`:
 *
 * - **moving a folder rewrites the whole subtree's `path` and `depth`**, and must refuse a move into
 *   the folder's own descendant, which would detach the branch from the library;
 * - **deleting a folder never deletes a quiz.** A QuizTemplate is hard-deleted in this application
 *   and there is no corbeille to fish it out of, so the content of a deleted folder - sub-folders
 *   and quizzes alike - is promoted one level up. That is the difference with the file library,
 *   where a folder goes to the corbeille with everything under it.
 *
 * Nothing here flushes. The caller owns its unit of work, as everywhere else in this application.
 */
class QuizFolderManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QuizFolderRepository $folders,
        private readonly QuizTemplateRepository $quizzes,
        private readonly QuizFolderTree $tree,
    ) {
    }

    public function createFolder(User $owner, ?QuizFolder $parent, string $name): QuizFolder
    {
        $folder = new QuizFolder($owner, $this->tree->uniqueName($name, $this->folders->siblingNames($owner, $parent)));
        $folder->setParent($parent);
        $folder->setPath($this->tree->pathFor($parent));
        $folder->setDepth($this->tree->depthOf($folder->getPath()));
        $folder->setCreatedBy($owner);

        $this->entityManager->persist($folder);

        return $folder;
    }

    public function rename(QuizFolder $folder, string $name, User $by): void
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
    public function moveFolder(QuizFolder $folder, ?QuizFolder $newParent, User $by): bool
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
     * Files a quiz into a folder, or back at the root when $folder is null - the drag this feature
     * exists for.
     *
     * @return bool false when the folder belongs to another library. A quiz cannot be filed
     *              somewhere its own teacher does not read
     */
    public function moveQuiz(QuizTemplate $quiz, ?QuizFolder $folder, User $by): bool
    {
        if (null !== $folder && $folder->getOwner()->getId() !== $quiz->getTeacher()?->getId()) {
            return false;
        }

        $quiz->setFolder($folder);
        $quiz->setLastUpdatedBy($by);
        $quiz->setLastUpdatedDate(new \DateTimeImmutable());

        return true;
    }

    /**
     * Deletes a folder and **promotes its content one level up** - sub-folders and quizzes alike.
     *
     * Only the direct children need moving: everything deeper keeps the same parent, and its path is
     * rewritten by the move of the branch it hangs from.
     */
    public function delete(QuizFolder $folder, User $by): void
    {
        $parent = $folder->getParent();

        foreach ($this->folders->findChildren($folder->getOwner(), $folder) as $child) {
            $this->moveFolder($child, $parent, $by);
        }

        foreach ($this->quizzes->findInFolder($folder->getOwner(), $folder) as $quiz) {
            $this->moveQuiz($quiz, $parent, $by);
        }

        $this->entityManager->remove($folder);
    }

    private function touch(QuizFolder $folder, User $by): void
    {
        $folder->setLastUpdatedBy($by);
        $folder->setLastUpdatedDate(new \DateTimeImmutable());
    }
}
