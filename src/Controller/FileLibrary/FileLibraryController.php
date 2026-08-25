<?php

declare(strict_types=1);

namespace App\Controller\FileLibrary;

use App\Attribute\RequiresFeature;
use App\Entity\FileLibraryNode;
use App\Enum\Feature;
use App\Repository\FileLibraryNodeRepository;
use App\Security\Voter\FileLibraryVoter;
use App\Service\FileLibraryLinks;
use App\Service\FileLibraryQuota;
use App\Service\FileLibraryTree;
use App\Service\FileLibraryUploadValidator;
use App\Service\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Reading a file library: the folder being browsed, the name search, and the corbeille
 * (design/validated/file-library.md, "Screens and routes"; mockups 1, 2, 3 and 9).
 *
 * `/tools/file-library/search` before `/tools/file-library/{nodeId}` is **not** the ordering trap it
 * looks like: `{nodeId}` carries `\d+`, so `search` cannot match it. The requirement is what makes
 * the order irrelevant, and it is there for that reason.
 *
 * Every filter is read through App\Service\QueryValue and never `InputBag::getInt()`: a filter bar
 * whose "Toutes" option is `value=""` submits `?x=` as a matter of course, and that answers a 400 -
 * which reached production here once already.
 */
#[IsGranted(FileLibraryVoter::VIEW)]
#[Route(path: '/tools/file-library')]
#[RequiresFeature(Feature::FileLibrary)]
class FileLibraryController extends AbstractController
{
    use FileLibraryTrait;

    public function __construct(
        private readonly FileLibraryNodeRepository $nodes,
        private readonly FileLibraryTree $tree,
        private readonly FileLibraryQuota $quota,
        private readonly FileLibraryUploadValidator $uploadValidator,
        private readonly FileLibraryLinks $links,
    ) {
    }

    #[Route(path: '', name: 'app_file_library', methods: ['GET'])]
    public function root(Request $request): Response
    {
        return $this->browse($request, null);
    }

    #[Route(path: '/{nodeId}', name: 'app_file_library_folder', requirements: ['nodeId' => '\d+'], methods: ['GET'])]
    public function folder(Request $request, int $nodeId): Response
    {
        $node = $this->loadNode($this->nodes, $nodeId);

        // A file has no screen of its own: it is a row of its folder, and the preview is an overlay
        // over that folder. Landing on one - from a usage panel, from a stale link - shows it where
        // it lives rather than answering 404.
        if (null !== $node && $node->isFile()) {
            $parent = $node->getParent();

            return null === $parent
                ? $this->redirectToRoute('app_file_library')
                : $this->redirectToRoute('app_file_library_folder', ['nodeId' => $parent->getId()]);
        }

        return $this->browse($request, $node);
    }

    #[Route(path: '/search', name: 'app_file_library_search', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $owner = $this->currentUser();
        $terms = QueryValue::trimmed($request, 'q');

        return $this->render('file_library/search.html.twig', [
            'terms' => $terms,
            'results' => '' === $terms ? [] : $this->nodes->search($owner, $terms),
            'rail' => $this->railTree($this->nodes, $this->tree, $owner),
            'quota' => $this->quotaBar($this->quota, $owner),
            'currentFolder' => null,
        ]);
    }

    /**
     * The corbeille (mockup 9): what was deleted, with the days left before the bytes go.
     *
     * The window is App\Service\ObjectStore's, not this screen's - it reads the same constant the
     * purge does, so a deployment that changes the retention changes both at once.
     */
    #[Route(path: '/trash', name: 'app_file_library_trash', methods: ['GET'])]
    public function trash(): Response
    {
        $owner = $this->currentUser();
        $deleted = $this->nodes->findDeleted($owner);
        $retention = \App\Service\ObjectStore::retentionDaysFor('file-library');
        $now = new \DateTimeImmutable();

        return $this->render('file_library/trash.html.twig', [
            'rows' => array_map(
                static function (FileLibraryNode $node) use ($retention, $now): array {
                    $deadline = ($node->getDeletedAt() ?? $now)->modify(\sprintf('+%d days', $retention));

                    return [
                        'node' => $node,
                        // Rounded up: "restorable for another 0 days" on the last morning would read
                        // as "gone", and it is not - the purge runs once a night.
                        'daysLeft' => max(0, (int) ceil(($deadline->getTimestamp() - $now->getTimestamp()) / 86400)),
                    ];
                },
                $deleted,
            ),
            'rail' => $this->railTree($this->nodes, $this->tree, $owner),
            'quota' => $this->quotaBar($this->quota, $owner),
            'currentFolder' => null,
        ]);
    }

    private function browse(Request $request, ?FileLibraryNode $folder): Response
    {
        $owner = $this->currentUser();
        $sort = QueryValue::trimmed($request, 'sort');
        $sort = \in_array($sort, ['name', 'size', 'date'], true) ? $sort : 'name';

        $children = $this->nodes->findChildren($owner, $folder, $sort);

        return $this->render('file_library/browse.html.twig', [
            'currentFolder' => $folder,
            'ancestors' => $this->ancestorsOf($this->nodes, $folder),
            'rows' => array_map(fn (FileLibraryNode $node): array => [
                'node' => $node,
                // A folder's "taille" column is its file count - the same column, reading the one
                // number that means something for a container.
                'fileCount' => $node->isFolder() ? $this->nodes->countFilesUnder($node) : null,
                // And the "utilisations" column is where the node is linked. It is a link into the
                // usage panel, and what turns « Supprimer » into « Supprimer partout ». A folder has
                // one kind of usage and one only - a class it was shared with - and it needs the
                // same warning: deleting it withdraws the listing from that class.
                'usageCount' => $this->links->countUsagesOf($node),
            ], $children),
            'rail' => $this->railTree($this->nodes, $this->tree, $owner),
            'quota' => $this->quotaBar($this->quota, $owner),
            'sort' => $sort,
            'maxBytes' => $this->uploadValidator->announcedMaxBytes(),
        ]);
    }
}
