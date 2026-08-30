<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SequenceFolder;
use App\Entity\User;
use App\Repository\SequenceFolderRepository;
use App\Security\Voter\SequenceFolderVoter;
use App\Service\SequenceFolderTree;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The handful of things the screens of the sequence library's classement need: the folder named in
 * the URL, the rail, the ancestors the breadcrumb draws, and the way back to a folder after a POST.
 *
 * A trait rather than a base class - App\Controller\QuizLibraryFolderTrait's shape, and for the same
 * reason: these controllers already extend AbstractController and what they share is a set of
 * helpers, not a hierarchy.
 */
trait SequenceLibraryFolderTrait
{
    /**
     * The folder named in the URL, or null for the root of the library.
     *
     * **The Voter is asked here and nowhere else**, so a folder belonging to somebody else answers
     * 403 rather than rendering - and an id that does not exist answers 404, which are two different
     * things a reader is entitled to be told apart.
     */
    private function loadSequenceFolder(SequenceFolderRepository $folders, ?int $folderId): ?SequenceFolder
    {
        if (null === $folderId) {
            return null;
        }

        $folder = $folders->find($folderId) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(SequenceFolderVoter::EDIT, $folder);

        return $folder;
    }

    /**
     * The rail: the library's folders, nested. Séquences are the listing's business - a rail
     * carrying both would be the same list twice.
     *
     * @return list<array{id: int, parentId: int|null, name: string, path: string, children: list<array<string, mixed>>}>
     */
    private function sequenceRailTree(SequenceFolderRepository $folders, SequenceFolderTree $tree, User $owner): array
    {
        $rows = array_map(
            static fn (SequenceFolder $folder): array => [
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
     * @return list<SequenceFolder>
     */
    private function sequenceAncestorsOf(SequenceFolderRepository $folders, ?SequenceFolder $folder): array
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

    /** Back to the folder that was being looked at - the root when there is none. */
    private function backToSequenceFolder(?SequenceFolder $folder): Response
    {
        return null === $folder
            ? $this->redirectToRoute('app_library_sequences')
            : $this->redirectToRoute('app_library_sequences_folder', ['folderId' => $folder->getId()]);
    }

    private function assertSequenceFolderCsrf(Request $request, string $tokenId): void
    {
        // Both spellings, because a small action inside a form is one of this repository's two
        // recurring bug classes: the token read from the header on a fetch, from the body on a form.
        $token = $request->headers->get('X-CSRF-Token') ?? $request->request->get('_token');

        if (!$this->isCsrfTokenValid($tokenId, \is_string($token) ? $token : null)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
