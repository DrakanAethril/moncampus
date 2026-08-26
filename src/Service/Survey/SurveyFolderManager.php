<?php

declare(strict_types=1);

namespace App\Service\Survey;

use App\Entity\SurveyFolder;
use App\Entity\SurveyTemplate;
use App\Entity\User;
use App\Repository\SurveyFolderRepository;
use App\Repository\SurveyTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Everything that changes the shape of a survey library's classement: create a folder, rename it,
 * move it, move a model into one, delete a folder.
 *
 * App\Service\QuizFolderManager's rules, and for the same reasons:
 *
 * - **moving a folder rewrites the whole subtree's `path` and `depth`**, and must refuse a move into
 *   the folder's own descendant, which would detach the branch from the library;
 * - **deleting a folder never deletes a model.** Its content - sub-folders and models alike - is
 *   promoted one level up. There is no corbeille on this side either, and a SurveyTemplate is what
 *   a launched campaign was copied from: losing one to a folder deletion would leave a series
 *   pointing at nothing.
 *
 * Nothing here flushes. The caller owns its unit of work, as everywhere else in this application.
 */
class SurveyFolderManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SurveyFolderRepository $folders,
        private readonly SurveyTemplateRepository $templates,
        private readonly SurveyFolderTree $tree,
    ) {
    }

    public function createFolder(User $owner, ?SurveyFolder $parent, string $name): SurveyFolder
    {
        $folder = new SurveyFolder($owner, $this->tree->uniqueName($name, $this->folders->siblingNames($owner, $parent)));
        $folder->setParent($parent);
        $folder->setPath($this->tree->pathFor($parent));
        $folder->setDepth($this->tree->depthOf($folder->getPath()));
        $folder->setCreatedBy($owner);

        $this->entityManager->persist($folder);

        return $folder;
    }

    public function rename(SurveyFolder $folder, string $name, User $by): void
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
    public function moveFolder(SurveyFolder $folder, ?SurveyFolder $newParent, User $by): bool
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
     * Files a model into a folder, or back at the root when $folder is null - the drag this feature
     * exists for.
     *
     * Takes no author, unlike App\Service\QuizFolderManager::moveQuiz(): a SurveyTemplate carries
     * no AuditableTrait, so « qui a rangé » is a question this side cannot answer and touch() is the
     * whole trace filing one leaves.
     *
     * @return bool false when the folder belongs to another library. A model cannot be filed
     *              somewhere its own author does not read
     */
    public function moveTemplate(SurveyTemplate $template, ?SurveyFolder $folder): bool
    {
        if (null !== $folder && $folder->getOwner()->getId() !== $template->getOwner()?->getId()) {
            return false;
        }

        $template->setFolder($folder);
        $template->touch();

        return true;
    }

    /**
     * Deletes a folder and **promotes its content one level up** - sub-folders and models alike.
     *
     * Only the direct children need moving: everything deeper keeps the same parent, and its path is
     * rewritten by the move of the branch it hangs from.
     */
    public function delete(SurveyFolder $folder, User $by): void
    {
        $parent = $folder->getParent();

        foreach ($this->folders->findChildren($folder->getOwner(), $folder) as $child) {
            $this->moveFolder($child, $parent, $by);
        }

        foreach ($this->templates->findInFolder($folder->getOwner(), $folder) as $template) {
            $this->moveTemplate($template, $parent);
        }

        $this->entityManager->remove($folder);
    }

    private function touch(SurveyFolder $folder, User $by): void
    {
        $folder->setLastUpdatedBy($by);
        $folder->setLastUpdatedDate(new \DateTimeImmutable());
    }
}
