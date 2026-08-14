<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AudienceTargetable;
use App\Entity\Message;
use App\Entity\MessageAttachment;
use App\Entity\MessageThread;
use App\Entity\MessageThreadRecipient;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\MessageAudienceType;
use App\Form\MessageComposeType;
use App\Form\MessageReplyType;
use App\Repository\MessageRepository;
use App\Repository\MessageThreadRecipientRepository;
use App\Repository\MessageThreadRepository;
use App\Repository\UserRepository;
use App\Security\Voter\MessageThreadVoter;
use App\Service\AudienceResolver;
use App\Service\FileUploadService;
use App\Service\FormValue;
use App\Service\JsonRequestPayload;
use App\Service\MessageAudienceMerger;
use App\Service\MessageEmailNotifier;
use App\Service\MessageThreadRecipientSyncer;
use App\Service\MessagingAccessChecker;
use App\Service\PostValue;
use App\Service\QueryValue;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

// Internal messaging - see design/validated/internal-messaging.md. Every route here requires
// ROLE_USER (the class attribute below); there is no unauthenticated or tutor-reachable
// entry point anywhere in this controller, per that design's permission matrix.
#[IsGranted('ROLE_USER')]
class MessageController extends AbstractController
{
    private const string ATTACHMENT_PREFIX = 'messages/';

    // Initial batch size for a folder's list pane, and the increment "Charger plus" (rows(),
    // below) fetches each time - design/design_handoff_messagerie #1 replaces DataTables'
    // page-number pagination with this incremental load, so there's only ever the one page size
    // to tune, not a page-length picker.
    private const int PAGE_SIZE = 20;

    #[Route(path: '/messages', name: 'app_messages')]
    public function inbox(MessageThreadRecipientRepository $recipientRepository, MessageThreadRecipientSyncer $recipientSyncer, MessageRepository $messageRepository, TranslatorInterface $translator): Response
    {
        return $this->renderFolderIndex(MessageThreadRecipientRepository::FOLDER_INBOX, $recipientRepository, $recipientSyncer, $messageRepository, $translator);
    }

    #[Route(path: '/messages/sent', name: 'app_messages_sent')]
    public function sent(MessageThreadRecipientRepository $recipientRepository, MessageThreadRecipientSyncer $recipientSyncer, MessageRepository $messageRepository, TranslatorInterface $translator): Response
    {
        return $this->renderFolderIndex(MessageThreadRecipientRepository::FOLDER_SENT, $recipientRepository, $recipientSyncer, $messageRepository, $translator);
    }

    #[Route(path: '/messages/archived', name: 'app_messages_archived')]
    public function archivedList(MessageThreadRecipientRepository $recipientRepository, MessageThreadRecipientSyncer $recipientSyncer, MessageRepository $messageRepository, TranslatorInterface $translator): Response
    {
        return $this->renderFolderIndex(MessageThreadRecipientRepository::FOLDER_ARCHIVED, $recipientRepository, $recipientSyncer, $messageRepository, $translator);
    }

    private function renderFolderIndex(string $folder, MessageThreadRecipientRepository $recipientRepository, MessageThreadRecipientSyncer $recipientSyncer, MessageRepository $messageRepository, TranslatorInterface $translator): Response
    {
        $user = $this->currentUser();

        // Late-joiner catch-up (see MessageThreadRecipientSyncer) - only meaningful for Inbox: a
        // newly granted row is always unread/unarchived, so it can only ever surface there, never
        // in Sent or Archived.
        if (MessageThreadRecipientRepository::FOLDER_INBOX === $folder) {
            $recipientSyncer->syncForUser($user);
        }

        $rows = $recipientRepository->findFolderPage($user, $folder, 0, self::PAGE_SIZE);

        return $this->render('messages/index.html.twig', [
            'folder' => $folder,
            'counts' => $this->folderCounts($user, $recipientRepository),
            'rows' => array_map(fn (MessageThreadRecipient $r): array => $this->rowViewModel($r, $folder, $messageRepository, $recipientRepository, $translator), $rows),
            'total' => $recipientRepository->countFolder($user, $folder),
            'pageSize' => self::PAGE_SIZE,
            'selectedThreadId' => null,
        ]);
    }

    // Backs "Charger plus" (design/design_handoff_messagerie #1: "aucune pagination : Charger
    // plus incrémental") - returns a rendered HTML fragment (day headers + rows, via the same
    // messages/_thread_rows.html.twig partial the initial page render uses) rather than JSON, so
    // assets/controllers/message_inbox_controller.js only ever has to append markup, never
    // reimplement row rendering in JS. The client is responsible for collapsing a day header that
    // duplicates the one already at the bottom of the list - see that controller.
    #[Route(path: '/messages/rows', name: 'app_messages_rows')]
    public function rows(Request $request, MessageThreadRecipientRepository $recipientRepository, MessageRepository $messageRepository, TranslatorInterface $translator): JsonResponse
    {
        $folder = (string) $request->query->get('folder', MessageThreadRecipientRepository::FOLDER_INBOX);
        if (!\in_array($folder, [MessageThreadRecipientRepository::FOLDER_INBOX, MessageThreadRecipientRepository::FOLDER_SENT, MessageThreadRecipientRepository::FOLDER_ARCHIVED], true)) {
            throw $this->createNotFoundException();
        }

        $user = $this->currentUser();
        $offset = max(0, QueryValue::int($request, 'offset', 0));
        $selectedThreadId = QueryValue::int($request, 'selected', 0) ?: null;

        $rows = $recipientRepository->findFolderPage($user, $folder, $offset, self::PAGE_SIZE);
        $total = $recipientRepository->countFolder($user, $folder);

        return $this->json([
            'html' => $this->renderView('messages/_thread_rows.html.twig', [
                'rows' => array_map(fn (MessageThreadRecipient $r): array => $this->rowViewModel($r, $folder, $messageRepository, $recipientRepository, $translator), $rows),
                'selectedThreadId' => $selectedThreadId,
            ]),
            'total' => $total,
            'hasMore' => $offset + \count($rows) < $total,
        ]);
    }

    /** @return array{inbox: int, unread: int, sent: int, archived: int} */
    private function folderCounts(User $user, MessageThreadRecipientRepository $recipientRepository): array
    {
        return [
            'inbox' => $recipientRepository->countFolder($user, MessageThreadRecipientRepository::FOLDER_INBOX),
            'unread' => $recipientRepository->countUnreadForUser($user),
            'sent' => $recipientRepository->countFolder($user, MessageThreadRecipientRepository::FOLDER_SENT),
            'archived' => $recipientRepository->countFolder($user, MessageThreadRecipientRepository::FOLDER_ARCHIVED),
        ];
    }

    #[Route(path: '/messages/new', name: 'app_messages_new')]
    public function compose(
        Request $request,
        EntityManagerInterface $entityManager,
        MessagingAccessChecker $accessChecker,
        AudienceResolver $audienceResolver,
        MessageThreadRepository $threadRepository,
        UserRepository $userRepository,
        FileUploadService $fileUploadService,
        MessageEmailNotifier $emailNotifier,
        #[Target('app.message_body')] HtmlSanitizerInterface $sanitizer,
    ): Response {
        $sender = $this->currentUser();

        // The "reply privately to an announcement's sender" flow (see
        // MessageThreadVoter::REPLY / templates/messages/show.html.twig) - locks the whole
        // audience picker to one fixed, re-validated recipient.
        $lockedRecipient = null;
        $toId = QueryValue::int($request, 'to', 0);
        if ($toId > 0) {
            $candidate = $userRepository->find($toId);
            if (null !== $candidate && $accessChecker->canMessageIndividually($sender, $candidate)) {
                $lockedRecipient = $candidate;
            }
        }

        // Consumed exactly once, from wherever staged it (currently only
        // ProgramToolsController::sendGroupsToMessaging(), the Création de groupes tool's
        // "Envoyer par messagerie" action) - a redirect-and-prefill rather than composing the
        // message itself server-side, so the teacher always reviews/edits before it actually
        // sends. session::remove() both reads and clears the key, so a subsequent visit to this
        // same route (including the very POST that submits this form) never reapplies it.
        $pendingDraft = $request->getSession()->remove('pending_message_draft');
        // Staged by ProgramToolsController::sendGroupsToMessaging() and read once - it has been
        // through the session, so neither key is guaranteed to be there or to be a string.
        $draft = JsonRequestPayload::fromArray(\is_array($pendingDraft) ? $pendingDraft : []);

        $thread = new MessageThread($sender);
        // The audience set has #[Assert\Count(min: 1)] (App\Entity\AudienceTargetableTrait) -
        // real assignment only happens in applyComposedAudience() below, AFTER the form's own
        // isValid() call, which already validates this bound entity (including that constraint)
        // as part of handling the request. Without a placeholder here, every submission would
        // fail that count check before applyComposedAudience() ever runs, regardless of which
        // audience was actually picked - this value is always overwritten with the real one once
        // validation passes.
        $thread->setAudienceTypes([MessageAudienceType::Manual]);
        if (!$draft->isEmpty()) {
            $thread->setSubject($draft->string('subject'));
        }
        if (null !== $lockedRecipient) {
            $thread->setAudienceTypes([MessageAudienceType::Manual])->addManualRecipient($lockedRecipient);

            $inReplyToThreadId = QueryValue::int($request, 'inReplyToThread', 0);
            if ($inReplyToThreadId > 0) {
                $inReplyToThread = $threadRepository->find($inReplyToThreadId);
                // Only ever a navigation breadcrumb (see MessageThread's docblock) - still
                // requires the sender to actually be a participant on it, same as any other view.
                if (null !== $inReplyToThread && $this->isGranted(MessageThreadVoter::VIEW, $inReplyToThread)) {
                    $thread->setInReplyToThread($inReplyToThread);
                }
            }
        }

        $allowedAudienceTypes = $accessChecker->allowedAudienceTypes($sender);
        $allowedPrograms = $accessChecker->programsForAudienceShortcut($sender);

        $form = $this->createForm(MessageComposeType::class, $thread, [
            'sender' => $sender,
            'allowedAudienceTypes' => $allowedAudienceTypes,
            'programs' => $allowedPrograms,
            'lockedRecipient' => $lockedRecipient,
        ]);
        if (!$draft->isEmpty()) {
            $form->get('body')->setData($draft->string('body'));
        }
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $recipients = [];

            if (null === $lockedRecipient) {
                $manualIds = array_map('intval', PostValue::all($request, 'recipients'));
                $recipients = $this->applyComposedAudience($thread, $form, $sender, $allowedPrograms, $manualIds, $accessChecker, $audienceResolver);

                if ([] === $recipients) {
                    $form->addError(new FormError('messageAudienceEmptyError'));
                }
            }

            if ($form->isValid()) {
                if (null !== $lockedRecipient) {
                    $recipients = $audienceResolver->resolveRecipients($thread, $sender);
                }

                $entityManager->persist($thread);

                $body = $sanitizer->sanitize(FormValue::string($form, 'body'));
                $message = new Message($thread, $sender, $body);
                $entityManager->persist($message);

                $this->persistAttachments($message, $form->get('attachments')->getData(), $fileUploadService, $entityManager);

                $this->fanOutRecipients($thread, $sender, $recipients, $entityManager);

                $entityManager->flush();

                $emailNotifier->notify($message, $recipients);

                $this->addFlash('success', 'messageSentFlashMessage');

                return $this->redirectToRoute('app_messages_show', ['id' => $thread->getId()]);
            }
        }

        return $this->render('messages/compose.html.twig', [
            'form' => $form,
            'lockedRecipient' => $lockedRecipient,
            // Keyed by id, not a plain list - EntityType's expanded child FormViews are keyed by
            // the entity's own id (not by array position), so messages/compose.html.twig looks
            // each Program back up via `programsById[child.vars.value]` to render its effectif
            // pill (see that template's Formations pastilles).
            'programsById' => array_combine(array_map(static fn (Program $program): ?int => $program->getId(), $allowedPrograms), $allowedPrograms),
        ]);
    }

    // The core of the cumulative-audience redesign (design/design_handoff_messagerie) - turns
    // whichever of MessageComposeType's audienceProgram/audienceAllStudents/audienceAllTeachers/
    // audienceAllStaff/audienceManual checkboxes came back checked, plus the raw `recipients[]`
    // manual picks, into a concrete recipient list and configures $thread to match.
    //
    // The checked set is stored as such (App\Entity\AudienceTargetable), whether it holds one
    // audience or four - a combined thread is not flattened into a frozen Manual list, so
    // App\Service\MessageThreadRecipientSyncer keeps catching up late joiners through every
    // broadcast audience it names, exactly as it would if that audience had been the only one.
    // Only the picks made under the Manual checkbox stay fixed, which is what Manual means.
    //
    // Returns the resolved recipients (sender excluded, see AudienceResolver); an empty return
    // means nothing was actually selected/resolved and the caller should treat the form as
    // invalid.
    /**
     * @return list<User>
     */
    private function applyComposedAudience(MessageThread $thread, FormInterface $form, User $sender, array $allowedPrograms, array $manualIds, MessagingAccessChecker $accessChecker, AudienceResolver $audienceResolver): array
    {
        $checkedTypes = [];
        foreach (MessageComposeType::AUDIENCE_CHECKBOX_FIELDS as $field => $type) {
            if ($form->has($field) && true === $form->get($field)->getData()) {
                $checkedTypes[] = $type;
            }
        }

        $thread->setAudienceTypes($checkedTypes);

        if ($thread->hasAudienceType(MessageAudienceType::Manual)) {
            foreach ($accessChecker->resolveManualRecipients($sender, $manualIds) as $user) {
                $thread->addManualRecipient($user);
            }
        }

        if ($thread->hasAudienceType(MessageAudienceType::Program)) {
            /** @var ?Collection<int, Program> $submittedPrograms */
            $submittedPrograms = $form->has('programs') ? $form->get('programs')->getData() : null;
            foreach ($submittedPrograms?->toArray() ?? [] as $program) {
                if (\in_array($program, $allowedPrograms, true)) {
                    $thread->addProgram($program);
                }
            }

            $thread
                ->setIncludeStudents($form->has('includeStudents') && true === $form->get('includeStudents')->getData())
                ->setIncludeTeachers($form->has('includeTeachers') && true === $form->get('includeTeachers')->getData())
            ;
        }

        return $audienceResolver->resolveRecipients($thread, $sender);
    }

    // Backs the composer's live recipient counter (design/design_handoff_messagerie #2: "le
    // compteur de destinataires est calculé et dédoublonné côté serveur, et affiché de façon
    // identique dans la zone destinataires et dans le pied de page") - re-run on every audience
    // change by assets/controllers/message_composer_controller.js. Every submitted id is
    // re-validated against this sender's own permission matrix exactly like the real submit path,
    // so the preview can never claim a count the actual send wouldn't also reach.
    #[Route(path: '/messages/recipient-count', name: 'app_messages_recipient_count', methods: ['POST'])]
    public function recipientCount(Request $request, MessagingAccessChecker $accessChecker, MessageAudienceMerger $audienceMerger): JsonResponse
    {
        $sender = $this->currentUser();
        $allowedAudienceTypes = $accessChecker->allowedAudienceTypes($sender);
        $allowedPrograms = $accessChecker->programsForAudienceShortcut($sender);

        $checkedTypes = [];
        foreach (MessageComposeType::AUDIENCE_CHECKBOX_FIELDS as $field => $type) {
            if ($request->request->getBoolean($field) && \in_array($type, $allowedAudienceTypes, true)) {
                $checkedTypes[] = $type;
            }
        }

        $submittedProgramIds = array_map('intval', PostValue::all($request, 'programs'));
        $programs = array_values(array_filter($allowedPrograms, static fn (Program $program): bool => \in_array($program->getId(), $submittedProgramIds, true)));

        $manualUsers = \in_array(MessageAudienceType::Manual, $checkedTypes, true)
            ? $accessChecker->resolveManualRecipients($sender, array_map('intval', PostValue::all($request, 'recipients')))
            : [];

        $recipients = $audienceMerger->merge(
            $sender,
            $checkedTypes,
            $programs,
            $request->request->getBoolean('includeStudents'),
            $request->request->getBoolean('includeTeachers'),
            $manualUsers,
        );

        return $this->json(['count' => \count($recipients)]);
    }

    #[Route(path: '/messages/mark-all-read', name: 'app_messages_mark_all_read', methods: ['POST'])]
    public function markAllRead(Request $request, MessageThreadRecipientRepository $recipientRepository): Response
    {
        if (!$this->isCsrfTokenValid('message_mark_all_read', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $recipientRepository->markAllReadForUser($this->currentUser());

        return $this->redirectToRoute('app_messages');
    }

    // Backs the tom-select ajax widget for manual recipients (see MessageComposeType's class
    // docblock) - returns just the matching page of candidates, never a full user list.
    #[Route(path: '/messages/recipients-search', name: 'app_messages_recipients_search')]
    public function recipientsSearch(Request $request, MessagingAccessChecker $accessChecker): JsonResponse
    {
        $limit = 20;
        $candidates = $accessChecker->searchCandidateRecipients($this->currentUser(), $request->query->get('q'), $limit);

        return $this->json([
            'results' => array_map(static fn (User $user): array => [
                'id' => $user->getId(),
                'text' => $user->getDisplayName() ?? $user->getUsername(),
            ], $candidates),
            'pagination' => ['more' => \count($candidates) === $limit],
        ]);
    }

    #[Route(path: '/messages/{id}', name: 'app_messages_show')]
    public function show(int $id, MessageThreadRepository $threadRepository, MessageRepository $messageRepository, MessageThreadRecipientRepository $recipientRepository, MessageThreadRecipientSyncer $recipientSyncer, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        $thread = $threadRepository->find($id) ?? throw $this->createNotFoundException();

        // Late-joiner catch-up (see MessageThreadRecipientSyncer) - must run before the VIEW check
        // below, which requires an existing MessageThreadRecipient row: a deep link to a Program/
        // AllStudents/AllTeachers/AllStaff thread the user only just became eligible for would
        // otherwise 404 before ever reaching the inbox listing that would have caught them up.
        $recipientSyncer->syncForUserAndThread($this->currentUser(), $thread);

        $this->denyAccessUnlessGranted(MessageThreadVoter::VIEW, $thread);

        $user = $this->currentUser();
        $recipientRow = $recipientRepository->findOneForUserAndThread($user, $thread) ?? throw $this->createNotFoundException();
        // Captured before setLastReadAt() below flips it - messages/show.html.twig still needs to
        // show the "Non lu" badge for the message that was unread the moment this page was opened.
        $wasUnread = $recipientRow->isUnread();
        $recipientRow->setLastReadAt(new \DateTimeImmutable());
        $entityManager->flush();

        $isAnnouncementShaped = $recipientRepository->countRecipients($thread) > 1;
        $canReply = $this->isGranted(MessageThreadVoter::REPLY, $thread);

        // Which folder's list this thread is shown alongside (design/design_handoff_messagerie's
        // shared 3-pane shell - see messages/show.html.twig) - the same precedence
        // MessageThreadRecipientRepository::folderQueryBuilder() uses: archived always wins
        // (regardless of who sent it), otherwise Sent vs Inbox by sender.
        $folder = match (true) {
            null !== $recipientRow->getArchivedAt() => MessageThreadRecipientRepository::FOLDER_ARCHIVED,
            $thread->getSender() === $user => MessageThreadRecipientRepository::FOLDER_SENT,
            default => MessageThreadRecipientRepository::FOLDER_INBOX,
        };
        $rows = $recipientRepository->findFolderPage($user, $folder, 0, self::PAGE_SIZE);

        return $this->render('messages/show.html.twig', [
            'thread' => $thread,
            'recipient' => $recipientRow,
            'messages' => $messageRepository->findForThread($thread),
            'isAnnouncementShaped' => $isAnnouncementShaped,
            'canReply' => $canReply,
            'audienceLabel' => $this->audienceLabel($thread, $recipientRepository, $translator),
            'readStats' => $thread->getSender() === $user && $isAnnouncementShaped ? $recipientRepository->readStats($thread) : null,
            'replyForm' => $canReply ? $this->createForm(MessageReplyType::class) : null,
            'wasUnread' => $wasUnread,
            'folder' => $folder,
            'counts' => $this->folderCounts($user, $recipientRepository),
            'rows' => array_map(fn (MessageThreadRecipient $r): array => $this->rowViewModel($r, $folder, $messageRepository, $recipientRepository, $translator), $rows),
            'total' => $recipientRepository->countFolder($user, $folder),
            'pageSize' => self::PAGE_SIZE,
            'selectedThreadId' => $thread->getId(),
        ]);
    }

    #[Route(path: '/messages/{id}/reply', name: 'app_messages_reply', methods: ['POST'])]
    public function reply(
        int $id,
        Request $request,
        MessageThreadRepository $threadRepository,
        MessageThreadRecipientRepository $recipientRepository,
        EntityManagerInterface $entityManager,
        FileUploadService $fileUploadService,
        MessageEmailNotifier $emailNotifier,
        #[Target('app.message_body')] HtmlSanitizerInterface $sanitizer,
    ): Response {
        $thread = $threadRepository->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(MessageThreadVoter::REPLY, $thread);

        $form = $this->createForm(MessageReplyType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $sender = $this->currentUser();
            $body = $sanitizer->sanitize(FormValue::string($form, 'body'));
            $message = new Message($thread, $sender, $body);
            $entityManager->persist($message);

            $this->persistAttachments($message, $form->get('attachments')->getData(), $fileUploadService, $entityManager);

            $thread->touchLastMessageAt($message->getSentAt());

            // Resurrects the thread for the other participant if they'd soft-deleted their copy
            // - see MessageThreadRecipient's docblock.
            $otherParticipants = [];
            foreach ($recipientRepository->findAllForThread($thread) as $recipientRow) {
                if ($recipientRow->getUser() !== $sender) {
                    $otherParticipants[] = $recipientRow->getUser();

                    if (null !== $recipientRow->getDeletedAt()) {
                        $recipientRow->setDeletedAt(null);
                    }
                }
            }

            $entityManager->flush();

            $emailNotifier->notify($message, $otherParticipants);

            $this->addFlash('success', 'messageReplySentFlashMessage');
        } else {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('error', $error->getMessage());
            }
        }

        return $this->redirectToRoute('app_messages_show', ['id' => $id]);
    }

    // The reading pane's "Marquer comme non lu" icon (design/design_handoff_messagerie #3) -
    // bounces back to the folder list rather than re-rendering the thread, since the point of the
    // action is to leave it, now flagged unread again in the list.
    #[Route(path: '/messages/{id}/mark-unread', name: 'app_messages_mark_unread', methods: ['POST'])]
    public function markUnread(int $id, Request $request, MessageThreadRepository $threadRepository, MessageThreadRecipientRepository $recipientRepository, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('message_mark_unread', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $recipientRow = $this->ownRecipientRowOrNotFound($id, $threadRepository, $recipientRepository);
        $recipientRow->setLastReadAt(null);
        $entityManager->flush();

        $redirectRoute = match (true) {
            null !== $recipientRow->getArchivedAt() => 'app_messages_archived',
            $recipientRow->getThread()->getSender() === $this->currentUser() => 'app_messages_sent',
            default => 'app_messages',
        };

        return $this->redirectToRoute($redirectRoute);
    }

    #[Route(path: '/messages/{id}/archive', name: 'app_messages_archive', methods: ['POST'])]
    public function archive(int $id, Request $request, MessageThreadRepository $threadRepository, MessageThreadRecipientRepository $recipientRepository, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('message_archive', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $recipientRow = $this->ownRecipientRowOrNotFound($id, $threadRepository, $recipientRepository);
        $recipientRow->setArchivedAt(new \DateTimeImmutable());
        $entityManager->flush();

        return $this->redirectToRoute('app_messages');
    }

    #[Route(path: '/messages/{id}/unarchive', name: 'app_messages_unarchive', methods: ['POST'])]
    public function unarchive(int $id, Request $request, MessageThreadRepository $threadRepository, MessageThreadRecipientRepository $recipientRepository, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('message_unarchive', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $recipientRow = $this->ownRecipientRowOrNotFound($id, $threadRepository, $recipientRepository);
        $recipientRow->setArchivedAt(null);
        $entityManager->flush();

        return $this->redirectToRoute('app_messages_archived');
    }

    #[Route(path: '/messages/{id}/delete', name: 'app_messages_delete', methods: ['POST'])]
    public function delete(int $id, Request $request, MessageThreadRepository $threadRepository, MessageThreadRecipientRepository $recipientRepository, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('message_delete', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $recipientRow = $this->ownRecipientRowOrNotFound($id, $threadRepository, $recipientRepository);

        // Where to bounce back to - derived from the row's own state rather than trusting a
        // posted field, since the row already knows which folder it belonged to.
        $redirectRoute = match (true) {
            null !== $recipientRow->getArchivedAt() => 'app_messages_archived',
            $recipientRow->getThread()->getSender() === $this->currentUser() => 'app_messages_sent',
            default => 'app_messages',
        };

        $recipientRow->setDeletedAt(new \DateTimeImmutable());
        $entityManager->flush();

        return $this->redirectToRoute($redirectRoute);
    }

    private function ownRecipientRowOrNotFound(int $threadId, MessageThreadRepository $threadRepository, MessageThreadRecipientRepository $recipientRepository): MessageThreadRecipient
    {
        $thread = $threadRepository->find($threadId) ?? throw $this->createNotFoundException();

        return $recipientRepository->findOneForUserAndThread($this->currentUser(), $thread) ?? throw $this->createNotFoundException();
    }

    /** @param list<UploadedFile>|null $files */
    private function persistAttachments(Message $message, ?array $files, FileUploadService $fileUploadService, EntityManagerInterface $entityManager): void
    {
        foreach ($files ?? [] as $file) {
            $extension = $file->guessExtension() ?? $file->getClientOriginalExtension();
            $key = $fileUploadService->upload(self::ATTACHMENT_PREFIX, \sprintf('%s.%s', bin2hex(random_bytes(16)), $extension), $file);
            $entityManager->persist(new MessageAttachment($message, $key, $file->getClientOriginalName()));
        }
    }

    /** @param list<User> $recipients */
    private function fanOutRecipients(MessageThread $thread, User $sender, array $recipients, EntityManagerInterface $entityManager): void
    {
        $participants = $recipients;
        if (!\in_array($sender, $participants, true)) {
            $participants[] = $sender;
        }

        foreach ($participants as $participant) {
            $row = new MessageThreadRecipient($thread, $participant);
            if ($participant === $sender) {
                // The sender has necessarily "read" their own outgoing message.
                $row->setLastReadAt(new \DateTimeImmutable());
            }
            $entityManager->persist($row);
        }
    }

    // One list-pane row's worth of display data (design/design_handoff_messagerie #1) - shared by
    // renderFolderIndex() (initial page), rows() (the "Charger plus" fragment), and show()'s own
    // copy of the list alongside the reading pane, so all three render identically. A plain array
    // rather than a DTO class, consistent with this controller's existing rowForRecipient()-style
    // view-model convention.
    private function rowViewModel(MessageThreadRecipient $recipient, string $folder, MessageRepository $messageRepository, MessageThreadRecipientRepository $recipientRepository, TranslatorInterface $translator): array
    {
        $thread = $recipient->getThread();
        $latest = $messageRepository->findLatest($thread);
        $snippet = null !== $latest ? mb_strimwidth(trim(strip_tags($latest->getBody())), 0, 120, '…') : '';
        $attachmentCount = null !== $latest ? $latest->getAttachments()->count() : 0;

        $isSentRow = MessageThreadRecipientRepository::FOLDER_SENT === $folder && $thread->getSender() === $recipient->getUser();
        $counterpart = $isSentRow
            ? $this->audienceLabel($thread, $recipientRepository, $translator)
            : ($thread->getSender()->getDisplayName() ?? $thread->getSender()->getUsername());

        $isBroadcast = $recipientRepository->countRecipients($thread) > 1;

        $readStats = null;
        if ($isSentRow && $isBroadcast) {
            $stats = $recipientRepository->readStats($thread);
            $readStats = \sprintf('%d/%d', $stats['read'], $stats['total']);
        }

        return [
            'id' => $thread->getId(),
            'unread' => $recipient->isUnread(),
            'initials' => $this->initialsFor($counterpart),
            'avatarVariant' => $thread->getId() % 3,
            'name' => $counterpart,
            'subject' => $thread->getSubject(),
            'snippet' => $snippet,
            'sentAt' => $thread->getLastMessageAt(),
            'attachmentCount' => $attachmentCount,
            'isBroadcast' => $isBroadcast,
            // Only meaningful for a broadcast the current user *received* - shown on the row as
            // "portée" (e.g. "Tous les enseignants") since counterpart above already shows the
            // sender's name there; a broadcast the user *sent* shows the audience as counterpart
            // instead (see $isSentRow above), so this stays null for that row to avoid repeating it.
            'portee' => $isBroadcast && !$isSentRow ? $this->audienceLabel($thread, $recipientRepository, $translator) : null,
            'readStats' => $readStats,
        ];
    }

    // "Florent Sautour" -> "FS", "Direction" -> "DI" - same two-letter-initials shape the
    // mockup's avatars use throughout, whether the name is a person or a department/service.
    private function initialsFor(string $name): string
    {
        $words = preg_split('/\s+/', trim($name), -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        if (\count($words) >= 2) {
            return mb_strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1));
        }

        return mb_strtoupper(mb_substr($name, 0, 2));
    }

    // Richer than App\Service\AudienceLabelFormatter for the Manual part alone: in an inbox a
    // one-recipient thread must read as that person's name, which means resolving recipients and
    // is exactly the coupling that formatter refuses. The rest matches it, separator included.
    private function audienceLabel(MessageThread $thread, MessageThreadRecipientRepository $recipientRepository, TranslatorInterface $translator): string
    {
        $types = $thread->getAudienceTypes();

        if ([] === $types) {
            return $this->manualAudienceLabel($thread, $recipientRepository, $translator);
        }

        return implode(' + ', array_map(fn (MessageAudienceType $type): string => match ($type) {
            MessageAudienceType::Program => \sprintf('%s — %s', $this->programsLabel($thread->getPrograms()), $this->rolesLabel($thread, $translator)),
            MessageAudienceType::Manual => $this->manualAudienceLabel($thread, $recipientRepository, $translator),
            default => $translator->trans($type->labelKey()),
        }, $types));
    }

    private function rolesLabel(AudienceTargetable $target, TranslatorInterface $translator): string
    {
        return match (true) {
            $target->isIncludeStudents() && $target->isIncludeTeachers() => $translator->trans('messageAudienceRoleBothLabel'),
            $target->isIncludeTeachers() => $translator->trans('messageAudienceRoleTeachersLabel'),
            default => $translator->trans('messageAudienceRoleStudentsLabel'),
        };
    }

    private function manualAudienceLabel(MessageThread $thread, MessageThreadRecipientRepository $recipientRepository, TranslatorInterface $translator): string
    {
        $recipients = $thread->getManualRecipients();

        if (1 === $recipients->count()) {
            $only = $recipients->first();

            return $only->getDisplayName() ?? $only->getUsername();
        }

        return $translator->trans('messageManualRecipientCountLabel', ['%count%' => $recipientRepository->countRecipients($thread)]);
    }

    /** @param Collection<int, Program> $programs */
    private function programsLabel(Collection $programs): string
    {
        if ($programs->isEmpty()) {
            return '—';
        }

        return implode(', ', array_map(static fn (Program $program): string => $program->getDisplayShortName(), $programs->toArray()));
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
