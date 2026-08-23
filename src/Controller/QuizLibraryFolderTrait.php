<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\QuizFolder;
use App\Entity\User;
use App\Repository\QuizFolderRepository;
use App\Security\Voter\QuizFolderVoter;
use App\Service\QuizFolderTree;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The handful of things the two screens of the quiz library's classement need: who is looking, the
 * folder named in the URL, the rail, and the way back to a folder after a POST.
 *
 * A trait rather than a base class - the same shape as App\Controller\FileLibrary\FileLibraryTrait,
 * and for the same reason: these controllers already extend AbstractController and what they share
 * is a set of helpers, not a hierarchy.
 */
trait QuizLibraryFolderTrait
{
    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    /**
     * The folder named in the URL, or null for the root of the library.
     *
     * **The Voter is asked here and nowhere else**, so a folder belonging to somebody else answers
     * 403 rather than rendering - and an id that does not exist answers 404, which are two different
     * things a reader is entitled to be told apart.
     */
    private function loadFolder(QuizFolderRepository $folders, ?int $folderId): ?QuizFolder
    {
        if (null === $folderId) {
            return null;
        }

        $folder = $folders->find($folderId) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(QuizFolderVoter::EDIT, $folder);

        return $folder;
    }

    /**
     * The rail: the library's folders, nested. Quizzes are the listing's business - a rail carrying
     * both would be the same list twice.
     *
     * @return list<array{id: int, parentId: int|null, name: string, path: string, children: list<array<string, mixed>>}>
     */
    private function railTree(QuizFolderRepository $folders, QuizFolderTree $tree, User $owner): array
    {
        $rows = array_map(
            static fn (QuizFolder $folder): array => [
                'id' => (int) $folder->getId(),
                'parentId' => $folder->getParent()?->getId(),
                'name' => $folder->getName(),
                'path' => $folder->getPath(),
            ],
            $folders->findAllFor($owner),
        );

        return $tree->assemble($rows);
    }

    /**
     * The ancestors of a folder, root first - the breadcrumb's middle segments, read off the
     * materialized path rather than by walking the parent chain (one query, whatever the depth).
     *
     * @return list<QuizFolder>
     */
    private function ancestorsOf(QuizFolderRepository $folders, ?QuizFolder $folder): array
    {
        if (null === $folder) {
            return [];
        }

        $ids = array_values(array_filter(array_map(intval(...), explode('/', trim($folder->getPath(), '/')))));

        if ([] === $ids) {
            return [];
        }

        $byId = [];

        foreach ($folders->findBy(['id' => $ids]) as $ancestor) {
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
     * A quiz's own place in the classement, root folder first and its own folder last - the middle
     * segments of the trail every quiz screen draws, so a quiz opened from a folder three levels
     * deep leads back into it rather than to the root of the library.
     *
     * Empty for a reader who is not the owner: the listing a folder link opens shows the *reader's*
     * quizzes (QuizTemplateRepository::findInFolder()), so pointing staff at a colleague's folder
     * would open a screen that says empty about a folder that is not.
     *
     * @return list<QuizFolder>
     */
    private function folderTrailOf(QuizFolderRepository $folders, ?QuizFolder $folder, User $reader): array
    {
        if (null === $folder || $folder->getOwner() !== $reader) {
            return [];
        }

        return [...$this->ancestorsOf($folders, $folder), $folder];
    }

    /** Back to the folder that was being looked at - the root when there is none. */
    private function backToFolder(?QuizFolder $folder): Response
    {
        return null === $folder
            ? $this->redirectToRoute('app_library_quiz')
            : $this->redirectToRoute('app_library_quiz_folder', ['folderId' => $folder->getId()]);
    }

    private function assertFolderCsrf(Request $request, string $tokenId): void
    {
        // Both spellings, because a small action inside a form is one of this repository's two
        // recurring bug classes: the token read from the header on a fetch, from the body on a form.
        $token = $request->headers->get('X-CSRF-Token') ?? $request->request->get('_token');

        if (!$this->isCsrfTokenValid($tokenId, \is_string($token) ? $token : null)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
