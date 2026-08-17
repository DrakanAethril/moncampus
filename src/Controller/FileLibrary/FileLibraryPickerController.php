<?php

declare(strict_types=1);

namespace App\Controller\FileLibrary;

use App\Entity\FileLibraryNode;
use App\Repository\FileLibraryNodeRepository;
use App\Security\Voter\FileLibraryVoter;
use App\Service\ByteSize;
use App\Service\FileLibraryLinks;
use App\Service\FileLibraryQuota;
use App\Service\FileLibraryTree;
use App\Service\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the picker's « Bibliothèque de fichiers » tab reads, and the usage panel
 * (design/validated/file-library.md, mockups 4 and 6).
 *
 * **The endpoint returns only the requester's own nodes**, and that is not the control: the form
 * re-checks every submitted id server-side, because a picker is a convenience and never a control.
 * Same posture as the wiki's member picker and messaging's recipients.
 */
#[IsGranted(FileLibraryVoter::VIEW)]
#[Route(path: '/tools/file-library')]
class FileLibraryPickerController extends AbstractController
{
    use FileLibraryTrait;

    public function __construct(
        private readonly FileLibraryNodeRepository $nodes,
        private readonly FileLibraryTree $tree,
        private readonly FileLibraryQuota $quota,
        private readonly FileLibraryLinks $links,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The tree and one folder's files, in one answer: the modal shows both at once, and two requests
     * for one screen would only make the folders arrive after the files.
     */
    #[Route(path: '/picker', name: 'app_file_library_picker', methods: ['GET'])]
    public function picker(Request $request): JsonResponse
    {
        $owner = $this->currentUser();
        $terms = QueryValue::trimmed($request, 'q');
        $folderId = QueryValue::nullableInt($request, 'folder');
        $folder = null;

        if (null !== $folderId) {
            $folder = $this->nodes->find($folderId);

            // Somebody else's folder is answered as "no folder" rather than as a 403: the tab is a
            // convenience, and the only thing it may ever show is this account's own files.
            if (null === $folder || $folder->getOwner()->getId() !== $owner->getId()) {
                $folder = null;
            }
        }

        $files = '' === $terms
            ? array_values(array_filter($this->nodes->findChildren($owner, $folder), static fn (FileLibraryNode $node): bool => $node->isFile()))
            : array_values(array_filter($this->nodes->search($owner, $terms), static fn (FileLibraryNode $node): bool => $node->isFile()));

        return $this->json([
            'folders' => $this->railTree($this->nodes, $this->tree, $owner),
            'folderId' => $folder?->getId(),
            'files' => array_map(static fn (FileLibraryNode $node): array => [
                'id' => $node->getId(),
                'name' => $node->getName(),
                'size' => ByteSize::format($node->getSizeBytes()),
                'extension' => $node->getExtension(),
            ], $files),
            'quota' => $this->quotaBar($this->quota, $owner),
        ]);
    }

    /**
     * The usage panel: where this file is used, by name, with a link to each.
     *
     * Reached from the table's usage count, which since this lot is the only way in - the row menu
     * deliberately does not carry one, six entries being already the most a menu can hold and still
     * be read.
     */
    // No `\d+`: the screen generates this address as a template carrying `__NODE_ID__`, and a
    // numeric requirement makes path() refuse to generate it - a 500 while *rendering* rather than a
    // 404 on use. Third time this trap appears in this feature; see FileLibraryNodeController.
    #[Route(path: '/{nodeId}/usages', name: 'app_file_library_node_usages', methods: ['GET'])]
    public function usages(int $nodeId): JsonResponse
    {
        $node = $this->loadNode($this->nodes, $nodeId);

        if (null === $node) {
            throw $this->createNotFoundException();
        }

        $usages = $this->links->usagesOf($node);

        return $this->json([
            'name' => $node->getName(),
            'count' => \count($usages),
            'usages' => array_map(fn (array $usage): array => [
                'where' => $this->translator->trans($usage['where']),
                'what' => $usage['what'],
                'url' => $usage['url'],
            ], $usages),
            // The line the deletion modal adds, and the whole of what the design asks it to say
            // beyond the list: anything more turns a confirmation into a lecture.
            'deleteNotice' => $this->translator->trans('fileLibraryDeleteEverywhereNoticeText'),
        ]);
    }
}
