<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\FileLibraryNode;
use App\Entity\SharedDocument;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\SharedDocumentGrouping;
use App\Enum\SharedDocumentOrdering;
use App\Repository\FileLibraryNodeRepository;
use App\Repository\SharedDocumentRepository;
use App\Service\FileLibraryArchiver;
use App\Service\FileLibrarySubtree;
use App\Service\FileUploadService;
use App\Service\QueryValue;
use App\Service\SharedDocumentAudience;
use App\Service\SharedDocumentBoard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Documents partagés » - what the teachers have put at this student's classes' disposal, and the
 * first entry of the student's Ressources menu.
 *
 * The screen owns no rule of its own: who may read what is App\Service\SharedDocumentAudience, and
 * the grouping and ordering are App\Service\SharedDocumentBoard. What is left here is reading two
 * filters off the query string - through App\Service\QueryValue, never `InputBag::getInt()`, since
 * both are `value=""`-able - and handing the file over.
 *
 * The download route re-asks the audience question rather than trusting that the list produced the
 * link: a share whose window has just closed must stop resolving, and a share belonging to somebody
 * else's class must never resolve at all.
 *
 * **A share names a file or a folder**, and one route answers for both. A file hands over its
 * address; a folder lists its whole content - subfolders included, at any depth - on a screen of its
 * own, whose rows come back here with `?node=` to open one. That screen is not the teacher's library
 * and must never become it: what was shared is one folder, so one folder is what is listed, and a
 * node named in the query string is served only after being proved to sit inside it. The same shape
 * as a folder shared to a colleague (App\Controller\ContentShareController), down to the check.
 *
 * Every row carries the *other* gesture too: download() saves rather than opens, and answers for a
 * folder with the archive of its whole subtree. The two share their resolution, so what a student
 * may download is by construction what they may open - there is no second place to get that wrong.
 *
 * **A video is watched, never handed over** (App\Service\UploadPolicy::isVideo()). open() answers
 * it with a player screen of the platform's own instead of the file's address, the address itself
 * is asked for by that player (playback()) and lives two hours, download() refuses it, and a
 * folder's archive leaves it out. None of that makes a video impossible to save - a browser that
 * plays a file has it - but no gesture of the platform offers it, and no link it prints does.
 */
#[IsGranted('ROLE_STUDENT')]
#[RequiresFeature(Feature::SharedDocuments)]
class StudentSharedDocumentController extends AbstractController
{
    public function __construct(
        private readonly SharedDocumentAudience $audience,
        private readonly SharedDocumentBoard $board,
        private readonly SharedDocumentRepository $sharedDocuments,
        private readonly FileLibrarySubtree $subtree,
    ) {
    }

    #[Route(path: '/my/shared-documents', name: 'app_student_shared_documents', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $grouping = SharedDocumentGrouping::fromRequestValue(QueryValue::trimmed($request, 'group'));
        $ordering = SharedDocumentOrdering::fromRequestValue(QueryValue::trimmed($request, 'order'));

        return $this->render('student_shared_document/index.html.twig', [
            'groups' => $this->board->build($this->audience->visibleFor($this->currentUser()), $grouping, $ordering),
            'grouping' => $grouping,
            'ordering' => $ordering,
            'groupings' => SharedDocumentGrouping::all(),
            'orderings' => SharedDocumentOrdering::all(),
        ]);
    }

    #[Route(path: '/my/shared-documents/{id}/open', name: 'app_student_shared_document_open', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function open(
        int $id,
        Request $request,
        FileUploadService $fileUploads,
        FileLibraryNodeRepository $nodes,
    ): Response {
        $share = $this->readableShareOrNotFound($id);
        $shared = $share->getLibraryNode();
        $node = $this->nodeToServe($share, $request, $nodes);

        if ($node->isVideo()) {
            return $this->render('student_shared_document/watch.html.twig', [
                'share' => $share,
                'shared' => $shared,
                'node' => $node,
            ]);
        }

        if ($node->isFile()) {
            return $this->redirect($fileUploads->downloadUrl($this->storageKeyOrNotFound($node), $node->getName()));
        }

        $rows = $this->subtree->rows($shared);

        return $this->render('student_shared_document/folder.html.twig', [
            'share' => $share,
            'node' => $shared,
            'rows' => $rows,
            'holdsVideos' => [] !== array_filter($rows, static fn (array $row): bool => $row['node']->isVideo()),
        ]);
    }

    /**
     * The playback address of a shared video, asked for by the player of the watching screen rather
     * than laid into the page: the HTML never carries it, and this is where the right to watch is
     * checked again - the share may have closed since the screen was opened.
     *
     * Only a video answers. Any other file has « Ouvrir » for that, and a route that signed an
     * address for whatever `?node=` names would be a second download route under another name.
     */
    #[Route(path: '/my/shared-documents/{id}/playback', name: 'app_student_shared_document_playback', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function playback(
        int $id,
        Request $request,
        FileUploadService $fileUploads,
        FileLibraryNodeRepository $nodes,
    ): Response {
        $node = $this->nodeToServe($this->readableShareOrNotFound($id), $request, $nodes);

        if (!$node->isVideo()) {
            throw $this->createNotFoundException();
        }

        return $this->json(['url' => $fileUploads->playbackUrl($this->storageKeyOrNotFound($node), $node->getName())]);
    }

    /**
     * « Télécharger », the gesture next to « Ouvrir » on every row of the two screens.
     *
     * A file is handed over as an attachment whatever its type - a link that says « Télécharger »
     * must save, where the name link leaves a PDF to open in the viewer. A folder has no address of
     * its own, so it is archived (App\Service\FileLibraryArchiver): the row that says « dossier »
     * gives back the whole thing, subfolders included, rather than sending the student to open its
     * files one by one.
     *
     * It resolves the share and the node exactly as open() does, and for the same reason: a link
     * already on the screen proves nothing about a window that has since closed.
     *
     * A video is refused - watched on the platform, never handed over - and left out of a folder's
     * archive for the same reason: the rows offer no « Télécharger » on it, and a URL typed by hand
     * must not be the way round that.
     */
    #[Route(path: '/my/shared-documents/{id}/download', name: 'app_student_shared_document_download', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function download(
        int $id,
        Request $request,
        FileUploadService $fileUploads,
        FileLibraryNodeRepository $nodes,
        FileLibraryArchiver $archiver,
    ): Response {
        $share = $this->readableShareOrNotFound($id);
        $node = $this->nodeToServe($share, $request, $nodes);

        if ($node->isFolder()) {
            return $archiver->respond($node, withVideos: false);
        }

        if ($node->isVideo()) {
            throw $this->createNotFoundException();
        }

        return $this->redirect($fileUploads->attachmentUrl($this->storageKeyOrNotFound($node), $node->getName()));
    }

    private function readableShareOrNotFound(int $id): SharedDocument
    {
        $share = $this->sharedDocuments->find($id) ?? throw $this->createNotFoundException();

        if (!$this->audience->isVisibleTo($share, $this->currentUser()) || $share->getLibraryNode()->isDeleted()) {
            throw $this->createNotFoundException();
        }

        return $share;
    }

    /**
     * The node a `?node=` names, proved to sit inside what was actually shared - without that proof
     * the id in the query string is the student's own, and would open any file of the teacher's
     * library. No `?node=` at all means the shared node itself.
     */
    private function nodeToServe(SharedDocument $share, Request $request, FileLibraryNodeRepository $nodes): FileLibraryNode
    {
        $shared = $share->getLibraryNode();
        $wanted = QueryValue::nullableInt($request, 'node');
        $node = null === $wanted ? $shared : $nodes->find($wanted) ?? throw $this->createNotFoundException();

        if ($node->getId() !== $shared->getId() && !$node->isDescendantOf($shared)) {
            throw $this->createNotFoundException();
        }

        if ($node->isDeleted()) {
            throw $this->createNotFoundException();
        }

        return $node;
    }

    private function storageKeyOrNotFound(FileLibraryNode $node): string
    {
        return $node->getStorageKey() ?? throw $this->createNotFoundException();
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
