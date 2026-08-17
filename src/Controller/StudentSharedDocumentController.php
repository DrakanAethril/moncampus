<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\SharedDocumentGrouping;
use App\Enum\SharedDocumentOrdering;
use App\Repository\SharedDocumentRepository;
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
 */
#[IsGranted('ROLE_STUDENT')]
class StudentSharedDocumentController extends AbstractController
{
    public function __construct(
        private readonly SharedDocumentAudience $audience,
        private readonly SharedDocumentBoard $board,
        private readonly SharedDocumentRepository $sharedDocuments,
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
    public function open(int $id, FileUploadService $fileUploads): Response
    {
        $share = $this->sharedDocuments->find($id) ?? throw $this->createNotFoundException();
        $node = $share->getLibraryNode();

        if (!$this->audience->isVisibleTo($share, $this->currentUser()) || $node->isDeleted() || null === $node->getStorageKey()) {
            throw $this->createNotFoundException();
        }

        return $this->redirect($fileUploads->url($node->getStorageKey()));
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
