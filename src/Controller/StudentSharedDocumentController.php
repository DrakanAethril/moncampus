<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\SharedDocumentGrouping;
use App\Enum\SharedDocumentOrdering;
use App\Repository\FileLibraryNodeRepository;
use App\Repository\SharedDocumentRepository;
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
 */
#[IsGranted('ROLE_STUDENT')]
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

    #[Route(path: '/my/shared-documents/{id}/open', name: 'app_student_shared_document_open', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function open(
        int $id,
        Request $request,
        FileUploadService $fileUploads,
        FileLibraryNodeRepository $nodes,
    ): Response {
        $share = $this->sharedDocuments->find($id) ?? throw $this->createNotFoundException();
        $shared = $share->getLibraryNode();

        if (!$this->audience->isVisibleTo($share, $this->currentUser()) || $shared->isDeleted()) {
            throw $this->createNotFoundException();
        }

        $wanted = QueryValue::nullableInt($request, 'node');
        $node = null === $wanted ? $shared : $nodes->find($wanted) ?? throw $this->createNotFoundException();

        // The id in the query string is the student's, so it is worth nothing until it is proved to
        // name something inside what was actually shared. Without this line it would open any file
        // of the teacher's library.
        if ($node->getId() !== $shared->getId() && !$node->isDescendantOf($shared)) {
            throw $this->createNotFoundException();
        }

        if ($node->isFile()) {
            if ($node->isDeleted() || null === $node->getStorageKey()) {
                throw $this->createNotFoundException();
            }

            return $this->redirect($fileUploads->url($node->getStorageKey()));
        }

        return $this->render('student_shared_document/folder.html.twig', [
            'share' => $share,
            'node' => $shared,
            'rows' => $this->subtree->rows($shared),
        ]);
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
