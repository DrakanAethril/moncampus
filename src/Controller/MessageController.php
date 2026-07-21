<?php

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
use App\Repository\SignupListRepository;
use App\Repository\UserRepository;
use App\Security\Voter\MessageThreadVoter;
use App\Service\AudienceResolver;
use App\Service\FileUploadService;
use App\Service\MessageEmailNotifier;
use App\Service\MessageThreadRecipientSyncer;
use App\Service\MessagingAccessChecker;
use App\Service\SignupListAccessChecker;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

// Internal messaging - see design/validated/internal-messaging.md. Every route here requires
// ROLE_USER (the class attribute below); there is no unauthenticated or ROLE_EXTERNAL-reachable
// entry point anywhere in this controller, per that design's permission matrix.
#[IsGranted('ROLE_USER')]
class MessageController extends AbstractController
{
    private const string ATTACHMENT_PREFIX = 'messages/';

    #[Route(path: '/messages', name: 'app_messages')]
    public function inbox(): Response
    {
        return $this->render('messages/index.html.twig', ['folder' => MessageThreadRecipientRepository::FOLDER_INBOX]);
    }

    #[Route(path: '/messages/sent', name: 'app_messages_sent')]
    public function sent(): Response
    {
        return $this->render('messages/index.html.twig', ['folder' => MessageThreadRecipientRepository::FOLDER_SENT]);
    }

    #[Route(path: '/messages/archived', name: 'app_messages_archived')]
    public function archivedList(): Response
    {
        return $this->render('messages/index.html.twig', ['folder' => MessageThreadRecipientRepository::FOLDER_ARCHIVED]);
    }

    #[Route(path: '/messages/data', name: 'app_messages_data')]
    public function data(Request $request, MessageThreadRecipientRepository $recipientRepository, MessageRepository $messageRepository, MessageThreadRecipientSyncer $recipientSyncer, TranslatorInterface $translator): JsonResponse
    {
        $folder = $request->query->get('folder', MessageThreadRecipientRepository::FOLDER_INBOX);
        if (!\in_array($folder, [MessageThreadRecipientRepository::FOLDER_INBOX, MessageThreadRecipientRepository::FOLDER_SENT, MessageThreadRecipientRepository::FOLDER_ARCHIVED], true)) {
            throw $this->createNotFoundException();
        }

        $user = $this->currentUser();

        // Late-joiner catch-up (see MessageThreadRecipientSyncer) - only meaningful for Inbox: a
        // newly granted row is always unread/unarchived, so it can only ever surface there, never
        // in Sent or Archived.
        if (MessageThreadRecipientRepository::FOLDER_INBOX === $folder) {
            $recipientSyncer->syncForUser($user);
        }

        $draw = $request->query->getInt('draw', 1);
        $start = max(0, $request->query->getInt('start', 0));
        $length = $request->query->getInt('length', 10);
        $length = $length > 0 ? min($length, 50) : 10;

        // Client-side search box is deliberately not wired here yet (see
        // assets/controllers/datatable_controller.js's "searching" value) - search across
        // subject/body is a v2 item, see design/validated/internal-messaging.md.
        $total = $recipientRepository->countFolder($user, $folder);
        $rows = $recipientRepository->findFolderPage($user, $folder, $start, $length);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $total,
            'data' => array_map(
                fn (MessageThreadRecipient $recipient): array => $this->rowForRecipient($recipient, $folder, $recipientRepository, $messageRepository, $translator),
                $rows,
            ),
        ]);
    }

    #[Route(path: '/messages/new', name: 'app_messages_new')]
    public function compose(
        Request $request,
        EntityManagerInterface $entityManager,
        MessagingAccessChecker $accessChecker,
        AudienceResolver $audienceResolver,
        MessageThreadRepository $threadRepository,
        UserRepository $userRepository,
        SignupListRepository $signupListRepository,
        SignupListAccessChecker $signupListAccessChecker,
        FileUploadService $fileUploadService,
        MessageEmailNotifier $emailNotifier,
        #[Target('app.message_body')] HtmlSanitizerInterface $sanitizer,
    ): Response {
        $sender = $this->currentUser();

        // The "reply privately to an announcement's sender" flow (see
        // MessageThreadVoter::REPLY / templates/messages/show.html.twig) - locks the whole
        // audience picker to one fixed, re-validated recipient.
        $lockedRecipient = null;
        $toId = $request->query->getInt('to', 0);
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

        $thread = new MessageThread($sender);
        if (\is_array($pendingDraft)) {
            $thread->setSubject((string) ($pendingDraft['subject'] ?? ''));
        }
        if (null !== $lockedRecipient) {
            $thread->setAudienceType(MessageAudienceType::Manual)->addManualRecipient($lockedRecipient);

            $inReplyToThreadId = $request->query->getInt('inReplyToThread', 0);
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
            'availableSignupLists' => $signupListRepository->findAvailableForAttachment($sender, $signupListAccessChecker->isStaff($sender), $thread->getSignupList()),
            'lockedRecipient' => $lockedRecipient,
        ]);
        if (\is_array($pendingDraft)) {
            $form->get('body')->setData((string) ($pendingDraft['body'] ?? ''));
        }
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (null === $lockedRecipient) {
                if (!\in_array($thread->getAudienceType(), $allowedAudienceTypes, true)) {
                    throw $this->createAccessDeniedException();
                }

                if (MessageAudienceType::Manual === $thread->getAudienceType()) {
                    foreach ($thread->getPrograms()->toArray() as $program) {
                        $thread->removeProgram($program);
                    }
                    $submittedIds = array_map('intval', $request->request->all('recipients'));
                    foreach ($accessChecker->resolveManualRecipients($sender, $submittedIds) as $recipient) {
                        $thread->addManualRecipient($recipient);
                    }
                } else {
                    foreach ($thread->getManualRecipients()->toArray() as $recipient) {
                        $thread->removeManualRecipient($recipient);
                    }

                    if (MessageAudienceType::Program !== $thread->getAudienceType()) {
                        foreach ($thread->getPrograms()->toArray() as $program) {
                            $thread->removeProgram($program);
                        }
                    } else {
                        foreach ($thread->getPrograms() as $program) {
                            if (!\in_array($program, $allowedPrograms, true)) {
                                // A forged program id outside what this sender is allowed to target.
                                throw $this->createAccessDeniedException();
                            }
                        }
                    }
                }
            }

            $entityManager->persist($thread);

            $body = $sanitizer->sanitize((string) $form->get('body')->getData());
            $message = new Message($thread, $sender, $body);
            $entityManager->persist($message);

            $this->persistAttachments($message, $form->get('attachments')->getData(), $fileUploadService, $entityManager);

            $recipients = $audienceResolver->resolveRecipients($thread, $sender);
            $this->fanOutRecipients($thread, $sender, $recipients, $entityManager);

            $entityManager->flush();

            $emailNotifier->notify($message, $recipients);

            $this->addFlash('success', 'messageSentFlashMessage');

            return $this->redirectToRoute('app_messages_show', ['id' => $thread->getId()]);
        }

        return $this->render('messages/compose.html.twig', [
            'form' => $form,
            'lockedRecipient' => $lockedRecipient,
        ]);
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
        $recipientRow->setLastReadAt(new \DateTimeImmutable());
        $entityManager->flush();

        $isAnnouncementShaped = $recipientRepository->countRecipients($thread) > 1;
        $canReply = $this->isGranted(MessageThreadVoter::REPLY, $thread);

        return $this->render('messages/show.html.twig', [
            'thread' => $thread,
            'recipient' => $recipientRow,
            'messages' => $messageRepository->findForThread($thread),
            'isAnnouncementShaped' => $isAnnouncementShaped,
            'canReply' => $canReply,
            'audienceLabel' => $this->audienceLabel($thread, $recipientRepository, $translator),
            'readStats' => $thread->getSender() === $user && $isAnnouncementShaped ? $recipientRepository->readStats($thread) : null,
            'replyForm' => $canReply ? $this->createForm(MessageReplyType::class) : null,
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
            $body = $sanitizer->sanitize((string) $form->get('body')->getData());
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

    private function rowForRecipient(MessageThreadRecipient $recipient, string $folder, MessageThreadRecipientRepository $recipientRepository, MessageRepository $messageRepository, TranslatorInterface $translator): array
    {
        $thread = $recipient->getThread();
        $latest = $messageRepository->findLatest($thread);
        $snippet = null !== $latest ? mb_strimwidth(trim(strip_tags($latest->getBody())), 0, 120, '…') : '';

        $counterpart = MessageThreadRecipientRepository::FOLDER_SENT === $folder && $thread->getSender() === $recipient->getUser()
            ? $this->audienceLabel($thread, $recipientRepository, $translator)
            : ($thread->getSender()->getDisplayName() ?? $thread->getSender()->getUsername());

        $readStats = null;
        if ($thread->getSender() === $recipient->getUser() && $recipientRepository->countRecipients($thread) > 1) {
            $stats = $recipientRepository->readStats($thread);
            $readStats = \sprintf('%d/%d', $stats['read'], $stats['total']);
        }

        $subjectText = htmlspecialchars($thread->getSubject(), \ENT_QUOTES);
        if ($recipient->isUnread()) {
            $subjectText = '<strong>'.$subjectText.'</strong>';
        }

        return [
            'id' => $thread->getId(),
            'subject' => \sprintf(
                '<a href="%s">%s</a><div class="text-secondary small">%s</div>',
                $this->generateUrl('app_messages_show', ['id' => $thread->getId()]),
                $subjectText,
                htmlspecialchars($snippet, \ENT_QUOTES),
            ),
            'counterpart' => $counterpart,
            'lastMessageAt' => $thread->getLastMessageAt()->format('d/m/Y H:i'),
            'readCount' => $readStats,
        ];
    }

    private function audienceLabel(MessageThread $thread, MessageThreadRecipientRepository $recipientRepository, TranslatorInterface $translator): string
    {
        return match ($thread->getAudienceType()) {
            MessageAudienceType::Program => \sprintf('%s — %s', $this->programsLabel($thread->getPrograms()), $this->rolesLabel($thread, $translator)),
            MessageAudienceType::Manual, null => $this->manualAudienceLabel($thread, $recipientRepository, $translator),
            default => $translator->trans($thread->getAudienceType()->labelKey()),
        };
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

        return implode(', ', array_map(static fn (Program $program): string => $program->getShortName(), $programs->toArray()));
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
