<?php

declare(strict_types=1);

namespace App\Controller\FileLibrary;

use App\Entity\FileLibraryNode;
use App\Repository\FileLibraryNodeRepository;
use App\Security\Voter\FileLibraryVoter;
use App\Service\FileLibraryNodeManager;
use App\Service\FileUploadService;
use App\Service\PostValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Everything that changes a library's shape: new folder, rename, move, delete, restore, download
 * (design/validated/file-library.md, "Screens and routes").
 *
 * **Every POST redirects**, which is Turbo's rule in this repository - the two exceptions are the
 * move, which is a drag and answers JSON to a fetch, and the download, which is a GET that hands
 * over a CDN address.
 *
 * The rules themselves are App\Service\FileLibraryNodeManager's: this class reads the request,
 * checks the Voter, and says what happened. Business rules belong in `src/Service/`.
 */
#[IsGranted(FileLibraryVoter::VIEW)]
#[Route(path: '/tools/file-library')]
class FileLibraryNodeController extends AbstractController
{
    use FileLibraryTrait;

    private const string CSRF_TOKEN_ID = 'file_library_node';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FileLibraryNodeRepository $nodes,
        private readonly FileLibraryNodeManager $manager,
    ) {
    }

    #[Route(path: '/folders/new', name: 'app_file_library_folder_new', methods: ['POST'])]
    public function newFolder(Request $request): Response
    {
        $this->assertCsrf($request);
        $parent = $this->parentFromRequest($request);
        $name = PostValue::trimmed($request, 'name');

        if ('' === $name) {
            $this->addFlash('error', 'fileLibraryFolderNameRequiredMessage');

            return $this->backTo($parent);
        }

        $folder = $this->manager->createFolder($this->currentUser(), $parent, $name);
        $this->entityManager->flush();
        $this->addFlash('success', 'fileLibraryFolderCreatedFlashMessage');

        return $this->backTo($folder->getParent());
    }

    // No `\d+` on nodeId here, unlike the browse routes, and it is not an oversight: the screen
    // generates this address as a template carrying a `__NODE_ID__` placeholder, and a numeric
    // requirement makes path() refuse to generate it at all - which throws while *rendering* and puts
    // the whole screen in 500. The same trap the video tool already carries a note about. The id is
    // cast and then looked up through the Voter, so nothing rests on the pattern.
    #[Route(path: '/{nodeId}/rename', name: 'app_file_library_node_rename', methods: ['POST'])]
    public function rename(Request $request, int $nodeId): Response
    {
        $this->assertCsrf($request);
        $node = $this->loadNode($this->nodes, $nodeId, FileLibraryVoter::EDIT);
        $name = PostValue::trimmed($request, 'name');

        if (null === $node || '' === $name) {
            $this->addFlash('error', 'fileLibraryFolderNameRequiredMessage');

            return $this->backTo($node?->getParent());
        }

        $this->manager->rename($node, $name, $this->currentUser());
        $this->entityManager->flush();
        $this->addFlash('success', 'fileLibraryRenamedFlashMessage');

        return $this->backTo($node->isFolder() ? $node->getParent() : $node->getParent());
    }

    /**
     * The one drag of this feature: a row of the table dropped onto a folder of the rail.
     *
     * JSON rather than a redirect because it is a fetch and not a form - the screen reloads itself
     * once the answer is in, which is what keeps the rail and the table in step without either of
     * them rebuilding the other.
     */
    // No `\d+`, same reason as rename() above: the rail generates this one as a template too.
    #[Route(path: '/{nodeId}/move', name: 'app_file_library_node_move', methods: ['POST'])]
    public function move(Request $request, int $nodeId): JsonResponse
    {
        $this->assertCsrf($request);
        $node = $this->loadNode($this->nodes, $nodeId, FileLibraryVoter::EDIT);

        if (null === $node) {
            throw $this->createNotFoundException();
        }

        $parent = $this->parentFromRequest($request);

        if (!$this->manager->move($node, $parent, $this->currentUser())) {
            // A folder dropped into its own descendant. Refused rather than thrown: the drag came
            // from a browser, and the screen simply redraws where the node still is.
            return $this->json(['error' => 'fileLibraryMoveRefusedMessage'], Response::HTTP_CONFLICT);
        }

        $this->entityManager->flush();

        return $this->json(['moved' => true]);
    }

    /**
     * The deletion, which since design/validated/object-deletion.md no longer promises destruction:
     * the file goes to the corbeille, and its bytes go thirty days later.
     *
     * The modal that lists every usage arrives with the link (lot 4) - there is nothing to list
     * until a file can be linked.
     */
    #[Route(path: '/{nodeId}/delete', name: 'app_file_library_node_delete', requirements: ['nodeId' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $nodeId): Response
    {
        $this->assertCsrf($request);
        $node = $this->loadNode($this->nodes, $nodeId, FileLibraryVoter::EDIT);

        if (null === $node) {
            throw $this->createNotFoundException();
        }

        $parent = $node->getParent();
        $this->manager->trash($node, $this->currentUser());
        $this->entityManager->flush();
        $this->addFlash('success', 'fileLibraryDeletedFlashMessage');

        return $this->backTo($parent);
    }

    #[Route(path: '/{nodeId}/restore', name: 'app_file_library_node_restore', requirements: ['nodeId' => '\d+'], methods: ['POST'])]
    public function restore(Request $request, int $nodeId): Response
    {
        $this->assertCsrf($request);
        $node = $this->loadNode($this->nodes, $nodeId, FileLibraryVoter::EDIT);

        if (null === $node) {
            throw $this->createNotFoundException();
        }

        $this->manager->restore($node, $this->currentUser());
        $this->entityManager->flush();
        // Said plainly rather than implied: the file is back, the links that were removed are not.
        $this->addFlash('success', 'fileLibraryRestoredFlashMessage');

        return $this->redirectToRoute('app_file_library_trash');
    }

    #[Route(path: '/{nodeId}/purge', name: 'app_file_library_node_purge', requirements: ['nodeId' => '\d+'], methods: ['POST'])]
    public function purge(Request $request, int $nodeId): Response
    {
        $this->assertCsrf($request);
        $node = $this->loadNode($this->nodes, $nodeId, FileLibraryVoter::EDIT);

        if (null === $node) {
            throw $this->createNotFoundException();
        }

        $this->manager->purgeNow($node);
        $this->entityManager->flush();
        $this->addFlash('success', 'fileLibraryPurgedFlashMessage');

        return $this->redirectToRoute('app_file_library_trash');
    }

    /**
     * The download: a redirect to the CDN address, exactly as every other stored file of this
     * application is served. Nothing is proxied through PHP.
     */
    #[Route(path: '/{nodeId}/download', name: 'app_file_library_node_download', requirements: ['nodeId' => '\d+'], methods: ['GET'])]
    public function download(int $nodeId, FileUploadService $fileUploads): Response
    {
        $node = $this->loadNode($this->nodes, $nodeId);

        if (null === $node || !$node->isFile() || null === $node->getStorageKey() || $node->isDeleted()) {
            throw $this->createNotFoundException();
        }

        return $this->redirect($fileUploads->url($node->getStorageKey()));
    }

    private function parentFromRequest(Request $request): ?FileLibraryNode
    {
        $parentId = PostValue::nullableInt($request, 'parent');

        if (null === $parentId) {
            return null;
        }

        $parent = $this->nodes->find($parentId) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(FileLibraryVoter::EDIT, $parent);

        return $parent;
    }

    private function backTo(?FileLibraryNode $folder): Response
    {
        return null === $folder
            ? $this->redirectToRoute('app_file_library')
            : $this->redirectToRoute('app_file_library_folder', ['nodeId' => $folder->getId()]);
    }

    private function assertCsrf(Request $request): void
    {
        $token = $request->headers->get('X-CSRF-Token') ?? $request->request->get('_token');

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, \is_string($token) ? $token : null)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
