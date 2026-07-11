<?php

namespace App\Controller;

use App\Entity\Ticket;
use App\Entity\TicketCategory;
use App\Entity\TicketComment;
use App\Entity\User;
use App\Form\TicketCategoryType;
use App\Form\TicketCommentType;
use App\Form\TicketManageType;
use App\Form\TicketType;
use App\Repository\TicketCategoryRepository;
use App\Repository\TicketCommentRepository;
use App\Repository\TicketRepository;
use App\Repository\UserRepository;
use App\Security\Voter\TicketVoter;
use App\Service\TicketDiscordNotifier;
use App\Service\TicketStatusFormatter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// No class-level IsGranted: "my tickets" routes are open to any ROLE_USER (the security.yaml
// catch-all already requires that), while the queue/category routes below are handler-only via
// a per-method IsGranted, and the ticket detail routes are gated per-object via TicketVoter
// instead of a role Expression - see denyAccessUnlessGranted() calls.
class TicketController extends AbstractController
{
    private const string HANDLER_ACCESS_EXPRESSION = 'is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD") or is_granted("ROLE_SUPPORT-TECH")';

    // The literal routes below (new/data/queue) are declared before showTicket()'s bare
    // '/tickets/{id}' route on purpose: Symfony tries routes in declaration order, and a bare
    // {id} placeholder would otherwise happily match "new"/"data"/"queue" as an id value.

    #[Route(path: '/tickets', name: 'app_tickets')]
    public function myTicketsTab(): Response
    {
        return $this->render('ticket/my_tickets.html.twig');
    }

    #[Route(path: '/tickets/new', name: 'app_tickets_new')]
    public function newTicketForm(Request $request, EntityManagerInterface $entityManager, TicketDiscordNotifier $discordNotifier): Response
    {
        $ticket = new Ticket($this->currentUser());

        $form = $this->createForm(TicketType::class, $ticket);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();

            $entityManager->persist($entity);
            $entityManager->flush();

            $discordNotifier->notifyNewTicket($entity);

            $this->addFlash('success', 'ticketCreatedFlashMessage');

            return $this->redirectToRoute('app_tickets_show', ['id' => $entity->getId()]);
        }

        return $this->render('ticket/ticket_new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(path: '/tickets/data', name: 'app_tickets_data')]
    public function myTicketsData(Request $request, TicketRepository $repository, TicketStatusFormatter $statusFormatter): JsonResponse
    {
        [$draw, $start, $length, $search] = $this->readDataTableParams($request);

        $reporter = $this->currentUser();
        $total = $repository->countForReporter($reporter);
        $filteredTotal = '' !== $search ? $repository->countForReporter($reporter, $search) : $total;
        $rows = $repository->findPageForReporter($reporter, $start, $length, '' !== $search ? $search : null);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(fn (Ticket $ticket): array => $this->ticketRow($ticket, $statusFormatter), $rows),
        ]);
    }

    #[IsGranted(new Expression(self::HANDLER_ACCESS_EXPRESSION))]
    #[Route(path: '/tickets/queue', name: 'app_tickets_queue')]
    public function queueTab(TicketCategoryRepository $categoryRepository, UserRepository $userRepository, TicketStatusFormatter $statusFormatter): Response
    {
        return $this->render('ticket/queue.html.twig', [
            'activeTab' => 'queue',
            'categories' => $categoryRepository->findAllActive(),
            'assignableUsers' => $userRepository->findActiveMatchingAnyRole(TicketVoter::HANDLER_ROLES),
            'statusChoices' => array_map(fn (string $status): array => ['value' => $status, 'label' => $statusFormatter->statusLabel($status)], Ticket::STATUSES),
            'priorityChoices' => array_map(fn (string $priority): array => ['value' => $priority, 'label' => $statusFormatter->priorityLabel($priority)], Ticket::PRIORITIES),
        ]);
    }

    #[IsGranted(new Expression(self::HANDLER_ACCESS_EXPRESSION))]
    #[Route(path: '/tickets/queue/categories', name: 'app_tickets_queue_categories')]
    public function queueCategoriesTab(): Response
    {
        return $this->render('ticket/queue.html.twig', ['activeTab' => 'categories']);
    }

    #[IsGranted(new Expression(self::HANDLER_ACCESS_EXPRESSION))]
    #[Route(path: '/tickets/queue/data', name: 'app_tickets_queue_data')]
    public function queueData(Request $request, TicketRepository $repository, TicketStatusFormatter $statusFormatter): JsonResponse
    {
        [$draw, $start, $length, $search, $status, $categoryId, $priority, $assigneeId] = $this->readQueueDataTableParams($request);

        $total = $repository->countAll(null, $status, $categoryId, $priority, $assigneeId);
        $filteredTotal = '' !== $search
            ? $repository->countAll($search, $status, $categoryId, $priority, $assigneeId)
            : $total;
        $rows = $repository->findPage($start, $length, '' !== $search ? $search : null, $status, $categoryId, $priority, $assigneeId);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(fn (Ticket $ticket): array => $this->ticketRow($ticket, $statusFormatter), $rows),
        ]);
    }

    #[IsGranted(new Expression(self::HANDLER_ACCESS_EXPRESSION))]
    #[Route(path: '/tickets/queue/categories/data', name: 'app_tickets_queue_categories_data')]
    public function queueCategoriesData(Request $request, TicketCategoryRepository $repository): JsonResponse
    {
        [$draw, $start, $length, $search, $includeInactive] = $this->readCategoryDataTableParams($request);

        $total = $repository->countAll(null, $includeInactive);
        $filteredTotal = '' !== $search ? $repository->countAll($search, $includeInactive) : $total;
        $rows = $repository->findPageOrderedByMostRecent($start, $length, '' !== $search ? $search : null, $includeInactive);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (TicketCategory $category): array => [
                    'id' => $category->getId(),
                    'isInactive' => null !== $category->getInactiveDate(),
                    'name' => $category->getName(),
                    'creationDate' => $category->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $category->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($category->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($category->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($category->getLastUpdatedBy()),
                    'lastUpdatedDate' => $category->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }

    #[IsGranted(new Expression(self::HANDLER_ACCESS_EXPRESSION))]
    #[Route(path: '/tickets/queue/categories/new', name: 'app_tickets_queue_categories_new')]
    #[Route(path: '/tickets/queue/categories/{id}/edit', name: 'app_tickets_queue_categories_edit')]
    public function categoryForm(Request $request, EntityManagerInterface $entityManager, TicketCategoryRepository $repository, ?int $id = null): Response
    {
        $category = null !== $id ? $this->findOrNotFound($repository, $id) : null;
        $isEdit = null !== $category;

        $form = $this->createForm(TicketCategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'ticketCategoryUpdatedFlashMessage' : 'ticketCategoryCreatedFlashMessage');

            return $this->redirectToRoute('app_tickets_queue_categories');
        }

        return $this->render('ticket/ticket_category_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[IsGranted(new Expression(self::HANDLER_ACCESS_EXPRESSION))]
    #[Route(path: '/tickets/queue/categories/{id}/deactivate', name: 'app_tickets_queue_categories_deactivate', methods: ['POST'])]
    public function deactivateCategory(Request $request, EntityManagerInterface $entityManager, TicketCategoryRepository $repository, int $id): JsonResponse
    {
        $category = $this->findOrNotFound($repository, $id);
        $this->assertValidToken('ticket_category_deactivate', $request);

        $category->setInactiveDate(new \DateTimeImmutable());
        $category->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/tickets/{id}', name: 'app_tickets_show')]
    public function showTicket(TicketRepository $repository, TicketCommentRepository $commentRepository, TicketStatusFormatter $statusFormatter, UserRepository $userRepository, int $id): Response
    {
        $ticket = $this->findOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(TicketVoter::VIEW, $ticket);

        return $this->renderShow($ticket, $commentRepository, $statusFormatter, $userRepository);
    }

    #[Route(path: '/tickets/{id}/comment', name: 'app_tickets_comment', methods: ['POST'])]
    public function addComment(Request $request, EntityManagerInterface $entityManager, TicketRepository $repository, TicketCommentRepository $commentRepository, TicketStatusFormatter $statusFormatter, UserRepository $userRepository, int $id): Response
    {
        $ticket = $this->findOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(TicketVoter::VIEW, $ticket);

        $isHandler = $this->isHandler($this->currentUser());
        $comment = new TicketComment($ticket, $this->currentUser(), '');

        $form = $this->createForm(TicketCommentType::class, $comment, ['allowInternalVisibility' => $isHandler]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Reporters never see the visibility field, but this is the actual enforcement
            // point, not the field being missing from the form.
            if (!$isHandler) {
                $comment->setVisibility(TicketComment::VISIBILITY_PUBLIC);
            }

            $entityManager->persist($comment);
            $entityManager->flush();

            $this->addFlash('success', 'ticketCommentAddedFlashMessage');

            return $this->redirectToRoute('app_tickets_show', ['id' => $ticket->getId()]);
        }

        return $this->renderShow($ticket, $commentRepository, $statusFormatter, $userRepository, commentForm: $form);
    }

    #[Route(path: '/tickets/{id}/manage', name: 'app_tickets_manage', methods: ['POST'])]
    public function manageTicket(Request $request, EntityManagerInterface $entityManager, TicketRepository $repository, TicketCommentRepository $commentRepository, TicketStatusFormatter $statusFormatter, UserRepository $userRepository, int $id): Response
    {
        $ticket = $this->findOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(TicketVoter::MANAGE, $ticket);

        $assignableUsers = $userRepository->findActiveMatchingAnyRole(TicketVoter::HANDLER_ROLES);

        $originalStatus = $ticket->getStatus();
        $originalPriority = $ticket->getPriority();
        $originalAssignee = $ticket->getAssignee();

        $form = $this->createForm(TicketManageType::class, $ticket, ['assignableUsers' => $assignableUsers]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $currentUser = $this->currentUser();

            // Status/priority/assignment changes are logged as system comments in the same
            // thread as human replies, so the thread doubles as the ticket's full history -
            // status changes are public (the reporter should know), priority/assignment are
            // internal operational detail. Written in whatever locale is active right now (via
            // TicketStatusFormatter's translator), same tradeoff as any other stored free text.
            if ($ticket->getStatus() !== $originalStatus) {
                $this->stampStatusTimestamps($ticket);
                $this->logSystemComment(
                    $entityManager,
                    $ticket,
                    $currentUser,
                    sprintf('%s → %s', $statusFormatter->statusLabel($originalStatus), $statusFormatter->statusLabel($ticket->getStatus())),
                    TicketComment::VISIBILITY_PUBLIC,
                );
            }

            if ($ticket->getPriority() !== $originalPriority) {
                $this->logSystemComment(
                    $entityManager,
                    $ticket,
                    $currentUser,
                    sprintf('%s → %s', $statusFormatter->priorityLabel($originalPriority), $statusFormatter->priorityLabel($ticket->getPriority())),
                    TicketComment::VISIBILITY_INTERNAL,
                );
            }

            if ($ticket->getAssignee() !== $originalAssignee) {
                $this->logSystemComment(
                    $entityManager,
                    $ticket,
                    $currentUser,
                    sprintf('%s → %s', $this->userLabel($originalAssignee), $this->userLabel($ticket->getAssignee())),
                    TicketComment::VISIBILITY_INTERNAL,
                );
            }

            $entityManager->flush();

            $this->addFlash('success', 'ticketUpdatedFlashMessage');

            return $this->redirectToRoute('app_tickets_show', ['id' => $ticket->getId()]);
        }

        return $this->renderShow($ticket, $commentRepository, $statusFormatter, $userRepository, manageForm: $form);
    }

    private function renderShow(
        Ticket $ticket,
        TicketCommentRepository $commentRepository,
        TicketStatusFormatter $statusFormatter,
        UserRepository $userRepository,
        ?FormInterface $commentForm = null,
        ?FormInterface $manageForm = null,
    ): Response {
        $isHandler = $this->isHandler($this->currentUser());
        $comments = $commentRepository->findForTicket($ticket, $isHandler);

        $commentForm ??= $this->createForm(TicketCommentType::class, new TicketComment($ticket, $this->currentUser(), ''), [
            'allowInternalVisibility' => $isHandler,
        ]);

        if ($isHandler && null === $manageForm) {
            $assignableUsers = $userRepository->findActiveMatchingAnyRole(TicketVoter::HANDLER_ROLES);
            $manageForm = $this->createForm(TicketManageType::class, $ticket, ['assignableUsers' => $assignableUsers]);
        }

        return $this->render('ticket/show.html.twig', [
            'ticket' => $ticket,
            'comments' => $comments,
            'isHandler' => $isHandler,
            'commentForm' => $commentForm,
            'manageForm' => $manageForm,
            'statusLabel' => $statusFormatter->statusLabel($ticket->getStatus()),
            'statusClass' => $statusFormatter->statusCssClass($ticket->getStatus()),
            'priorityLabel' => $statusFormatter->priorityLabel($ticket->getPriority()),
            'priorityClass' => $statusFormatter->priorityCssClass($ticket->getPriority()),
        ]);
    }

    private function isHandler(User $user): bool
    {
        return [] !== array_intersect(TicketVoter::HANDLER_ROLES, $user->getRoles());
    }

    private function stampStatusTimestamps(Ticket $ticket): void
    {
        $now = new \DateTimeImmutable();

        if (Ticket::STATUS_RESOLVED === $ticket->getStatus()) {
            $ticket->setResolvedAt($now);
        } elseif (Ticket::STATUS_CLOSED === $ticket->getStatus()) {
            $ticket->setClosedAt($now);
        } else {
            // Reopened - back to an active status, so it's no longer resolved/closed. Nothing is
            // lost: the system comment logged alongside this call still records when/by whom.
            $ticket->setResolvedAt(null);
            $ticket->setClosedAt(null);
        }
    }

    private function logSystemComment(EntityManagerInterface $entityManager, Ticket $ticket, User $author, string $body, string $visibility): void
    {
        $comment = (new TicketComment($ticket, $author, $body))
            ->setVisibility($visibility)
            ->setIsSystemGenerated(true);

        $entityManager->persist($comment);
    }

    /** @return array{id: int|null, subject: string, categoryName: string, location: string, reporterName: string, assigneeName: string, statusLabel: string, statusClass: string, priorityLabel: string, priorityClass: string, creationDate: string} */
    private function ticketRow(Ticket $ticket, TicketStatusFormatter $statusFormatter): array
    {
        return [
            'id' => $ticket->getId(),
            // Rendered as trusted HTML by the 'html' render keyword on this column (see
            // _my_tickets_content.html.twig / _queue_content.html.twig) - the default column
            // render escapes it.
            'subject' => sprintf(
                '<a href="%s">%s</a>',
                htmlspecialchars($this->generateUrl('app_tickets_show', ['id' => $ticket->getId()])),
                htmlspecialchars($ticket->getSubject()),
            ),
            'categoryName' => $ticket->getCategory()?->getName() ?? '—',
            'location' => $ticket->getRoom()?->getName() ?? $ticket->getOtherLocation() ?? '—',
            'reporterName' => $this->reporterLabel($ticket),
            'assigneeName' => null !== $ticket->getAssignee() ? $this->userLabel($ticket->getAssignee()) : '—',
            'statusLabel' => $statusFormatter->statusLabel($ticket->getStatus()),
            'statusClass' => $statusFormatter->statusCssClass($ticket->getStatus()),
            'priorityLabel' => $statusFormatter->priorityLabel($ticket->getPriority()),
            'priorityClass' => $statusFormatter->priorityCssClass($ticket->getPriority()),
            'creationDate' => $ticket->getCreationDate()->format('d/m/Y H:i'),
        ];
    }

    /** @return array{0: int, 1: int, 2: int, 3: string} */
    private function readDataTableParams(Request $request): array
    {
        $draw = $request->query->getInt('draw', 1);
        $start = max(0, $request->query->getInt('start', 0));
        $length = $request->query->getInt('length', 10);
        $length = $length > 0 ? min($length, 50) : 10;
        $search = trim((string) ($request->query->all('search')['value'] ?? ''));

        return [$draw, $start, $length, $search];
    }

    /** @return array{0: int, 1: int, 2: int, 3: string, 4: ?string, 5: ?int, 6: ?string, 7: ?int} */
    private function readQueueDataTableParams(Request $request): array
    {
        [$draw, $start, $length, $search] = $this->readDataTableParams($request);

        $status = trim((string) $request->query->get('status', ''));
        $categoryId = $request->query->getInt('categoryId', 0);
        $priority = trim((string) $request->query->get('priority', ''));
        $assigneeId = $request->query->getInt('assigneeId', 0);

        return [
            $draw, $start, $length, $search,
            '' !== $status ? $status : null,
            $categoryId > 0 ? $categoryId : null,
            '' !== $priority ? $priority : null,
            $assigneeId > 0 ? $assigneeId : null,
        ];
    }

    /** @return array{0: int, 1: int, 2: int, 3: string, 4: bool} */
    private function readCategoryDataTableParams(Request $request): array
    {
        [$draw, $start, $length, $search] = $this->readDataTableParams($request);
        $includeInactive = $request->query->getBoolean('includeInactive');

        return [$draw, $start, $length, $search, $includeInactive];
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    private function userLabel(?User $user): string
    {
        if (null === $user) {
            return '—';
        }

        return $user->getDisplayName() ?? $user->getUsername();
    }

    // Anonymous tickets (reported via the logged-out PublicTicketController form) have no User
    // to label - fall back to the self-reported name/contact so handlers still know who to
    // reach back out to.
    private function reporterLabel(Ticket $ticket): string
    {
        if (!$ticket->isAnonymous()) {
            return $this->userLabel($ticket->getReporter());
        }

        return sprintf('%s (%s)', $ticket->getReporterName() ?? '—', $ticket->getReporterContact() ?? '—');
    }

    /**
     * @template T of object
     *
     * @param ObjectRepository<T> $repository
     *
     * @return T
     */
    private function findOrNotFound(ObjectRepository $repository, int $id): object
    {
        return $repository->find($id) ?? throw $this->createNotFoundException();
    }

    private function stampAuditFields(object $entity, bool $isEdit): void
    {
        if ($isEdit) {
            $entity->setLastUpdatedBy($this->currentUser());
            $entity->setLastUpdatedDate(new \DateTimeImmutable());
        } else {
            $entity->setCreatedBy($this->currentUser());
        }
    }

    private function assertValidToken(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
