<?php

namespace App\Controller\Api;

use App\Entity\Message;
use App\Entity\MessageAttachment;
use App\Entity\MessageThread;
use App\Entity\MessageThreadRecipient;
use App\Entity\User;
use App\Enum\MessageAudienceType;
use App\Form\FileUploadDefaults;
use App\Repository\MessageRepository;
use App\Repository\MessageThreadRecipientRepository;
use App\Repository\MessageThreadRepository;
use App\Security\Voter\MessageThreadVoter;
use App\Service\AudienceLabelFormatter;
use App\Service\AudienceResolver;
use App\Service\FileUploadService;
use App\Service\MessageEmailNotifier;
use App\Service\MessagingAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Mobile counterpart to MessageController - same MessageThread/Message/MessageThreadRecipient
 * model and folder convention (see design/validated/internal-messaging.md and
 * MessageThreadRecipientRepository::FOLDER_*), just plain JSON instead of Twig/the web inbox's
 * DataTables-shaped /messages/data feed. Unlike the web compose form, the mobile compose screen
 * (design/design_campus_manager/README.md's "3h Nouveau message") has no audience-type picker at
 * all - every mobile-composed thread is Manual, and a "classe" recipient chip (teacher/staff only)
 * is expanded into that Program's students as individual manual recipients at send time instead
 * of going through the Program audience type's own live-membership syncing (MessageThreadRecipient
 * Syncer) - a one-off mobile message doesn't need a broadcast that keeps growing after send.
 */
class MessagesController extends AbstractController
{
    private const string ATTACHMENT_PREFIX = 'messages/';

    /** @var list<string> */
    private const array ATTACHMENT_MIME_TYPES = [
        'application/pdf', 'image/jpeg', 'image/png', 'image/webp',
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain', 'application/zip',
    ];

    #[Route(path: '/api/messages/threads', name: 'api_messages_threads', methods: ['GET'])]
    public function threads(Request $request, MessageThreadRecipientRepository $recipientRepository, MessageRepository $messageRepository): JsonResponse
    {
        $folder = $request->query->get('folder', MessageThreadRecipientRepository::FOLDER_INBOX);
        if (!\in_array($folder, [MessageThreadRecipientRepository::FOLDER_INBOX, MessageThreadRecipientRepository::FOLDER_SENT, MessageThreadRecipientRepository::FOLDER_ARCHIVED], true)) {
            throw $this->createNotFoundException();
        }

        $user = $this->currentUser();
        $offset = max(0, $request->query->getInt('offset', 0));
        $limit = $request->query->getInt('limit', 20);
        $limit = $limit > 0 ? min($limit, 50) : 20;

        $rows = $recipientRepository->findFolderPage($user, $folder, $offset, $limit);

        return $this->json([
            'total' => $recipientRepository->countFolder($user, $folder),
            'threads' => array_map(fn (MessageThreadRecipient $recipient): array => $this->formatThreadRow($recipient, $folder, $messageRepository), $rows),
        ]);
    }

    #[Route(path: '/api/messages/threads/{id}', name: 'api_messages_thread_show', methods: ['GET'])]
    public function show(
        int $id,
        MessageThreadRepository $threadRepository,
        MessageRepository $messageRepository,
        MessageThreadRecipientRepository $recipientRepository,
        EntityManagerInterface $entityManager,
        FileUploadService $fileUploadService,
        AudienceLabelFormatter $labelFormatter,
    ): JsonResponse {
        $thread = $threadRepository->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(MessageThreadVoter::VIEW, $thread);

        $user = $this->currentUser();
        $recipientRow = $recipientRepository->findOneForUserAndThread($user, $thread) ?? throw $this->createNotFoundException();

        // Marking as read on GET matches the web's show() action - a mobile client fetching the
        // thread body is the same "the user opened it" signal a page load is on the web.
        $recipientRow->setLastReadAt(new \DateTimeImmutable());
        $entityManager->flush();

        // "destinataires dépliables (+ 24 ▾)" (design 3i) - every participant but the viewer,
        // for the collapsible recipients list.
        $participantNames = array_values(array_filter(array_map(
            static fn (MessageThreadRecipient $row): ?string => $row->getUser() !== $user ? ($row->getUser()->getDisplayName() ?? $row->getUser()->getUsername()) : null,
            $recipientRepository->findAllForThread($thread),
        )));

        return $this->json([
            'id' => $thread->getId(),
            'subject' => $thread->getSubject(),
            'canReply' => $this->isGranted(MessageThreadVoter::REPLY, $thread),
            'messages' => array_map(fn (Message $message): array => $this->formatMessage($message, $fileUploadService), $messageRepository->findForThread($thread)),
            'recipientNames' => $participantNames,
            // Only meaningful for a broadcast-shaped thread (Program/AllStudents/AllTeachers/
            // AllStaff) - a mobile-composed Manual thread has no such shortcut label to show
            // beyond the recipient names themselves (see this class's docblock on why "classe"
            // chips are pre-expanded into individual manual recipients), so the client falls back
            // to "N destinataires" built from recipientNames when this is null.
            'audienceLabel' => \count($participantNames) > 1 && MessageAudienceType::Manual !== $thread->getAudienceType()
                ? $labelFormatter->format($thread)
                : null,
        ]);
    }

    #[Route(path: '/api/messages/unread-count', name: 'api_messages_unread_count', methods: ['GET'])]
    public function unreadCount(MessageThreadRecipientRepository $recipientRepository): JsonResponse
    {
        return $this->json(['count' => $recipientRepository->countUnreadForUser($this->currentUser())]);
    }

    // Backs the mobile compose screen's recipient chips (design 3h: "destinataires en chips avec
    // autocomplétion (enseignants, élèves, classes)"). Users come from the same permission matrix
    // as the web's tom-select widget (MessagingAccessChecker::searchCandidateRecipients); Programs
    // ("classes") are offered only to senders who actually have the audience shortcut
    // (programsForAudienceShortcut is empty for students), matching the README's "destinataires
    // « classe » réservés aux enseignants" rule for the mobile app in general.
    #[Route(path: '/api/messages/recipients-search', name: 'api_messages_recipients_search', methods: ['GET'])]
    public function recipientsSearch(Request $request, MessagingAccessChecker $accessChecker): JsonResponse
    {
        $sender = $this->currentUser();
        $query = $request->query->get('q');
        $limit = 20;

        $users = $accessChecker->searchCandidateRecipients($sender, $query, $limit);

        $programs = [];
        if ($accessChecker->isTeacher($sender) || $accessChecker->isStaff($sender)) {
            $needle = null !== $query ? mb_strtolower(trim($query)) : '';
            foreach ($accessChecker->programsForAudienceShortcut($sender) as $program) {
                if ('' === $needle || str_contains(mb_strtolower($program->getDisplayShortName()), $needle)) {
                    $programs[] = $program;
                }
            }
        }

        $results = array_map(static fn (User $user): array => [
            'type' => 'user',
            'id' => $user->getId(),
            'label' => $user->getDisplayName() ?? $user->getUsername(),
            'sublabel' => self::roleLabel($user),
        ], $users);

        foreach ($programs as $program) {
            $results[] = [
                'type' => 'program',
                'id' => $program->getId(),
                'label' => $program->getDisplayShortName(),
                'sublabel' => 'Classe',
            ];
        }

        return $this->json(['results' => $results]);
    }

    // Every mobile-composed thread is Manual - see this class's docblock for why "classe" chips
    // are expanded into individual manual recipients here instead of going through the Program
    // audience type.
    #[Route(path: '/api/messages', name: 'api_messages_compose', methods: ['POST'])]
    public function compose(
        Request $request,
        EntityManagerInterface $entityManager,
        MessagingAccessChecker $accessChecker,
        AudienceResolver $audienceResolver,
        FileUploadService $fileUploadService,
        MessageEmailNotifier $emailNotifier,
        ValidatorInterface $validator,
        #[Target('app.message_body')] HtmlSanitizerInterface $sanitizer,
    ): JsonResponse {
        $sender = $this->currentUser();

        $subject = trim((string) $request->request->get('subject', ''));
        $body = trim((string) $request->request->get('body', ''));

        if ('' === $subject) {
            return $this->json(['error' => 'subject_required'], 422);
        }

        if ('' === $body) {
            return $this->json(['error' => 'body_required'], 422);
        }

        $userIds = array_map('intval', $request->request->all('recipientUserIds'));
        $programIds = array_map('intval', $request->request->all('recipientProgramIds'));

        /** @var array<int, User> $recipients */
        $recipients = [];
        foreach ($accessChecker->resolveManualRecipients($sender, $userIds) as $user) {
            $recipients[$user->getId()] = $user;
        }

        if ([] !== $programIds) {
            if (!$accessChecker->isTeacher($sender) && !$accessChecker->isStaff($sender)) {
                return $this->json(['error' => 'programs_not_allowed'], 403);
            }

            $allowedPrograms = [];
            foreach ($accessChecker->programsForAudienceShortcut($sender) as $program) {
                $allowedPrograms[$program->getId()] = $program;
            }

            foreach ($programIds as $programId) {
                if (!isset($allowedPrograms[$programId])) {
                    return $this->json(['error' => 'program_not_allowed'], 403);
                }

                foreach ($allowedPrograms[$programId]->getStudents() as $student) {
                    $recipients[$student->getId()] = $student;
                }
            }
        }

        if ([] === $recipients) {
            return $this->json(['error' => 'recipients_required'], 422);
        }

        $attachmentsOrError = $this->validatedAttachments($request, $validator);
        if ($attachmentsOrError instanceof JsonResponse) {
            return $attachmentsOrError;
        }

        $thread = new MessageThread($sender);
        $thread->setSubject($subject)->setAudienceType(MessageAudienceType::Manual);
        foreach ($recipients as $recipient) {
            $thread->addManualRecipient($recipient);
        }
        $entityManager->persist($thread);

        $message = new Message($thread, $sender, $sanitizer->sanitize($body));
        $entityManager->persist($message);

        $this->persistAttachments($message, $attachmentsOrError, $fileUploadService, $entityManager);

        $resolvedRecipients = $audienceResolver->resolveRecipients($thread, $sender);
        $this->fanOutRecipients($thread, $sender, $resolvedRecipients, $entityManager);

        $entityManager->flush();

        $emailNotifier->notify($message, $resolvedRecipients);

        return $this->json(['id' => $thread->getId()], 201);
    }

    #[Route(path: '/api/messages/threads/{id}/reply', name: 'api_messages_reply', methods: ['POST'])]
    public function reply(
        int $id,
        Request $request,
        MessageThreadRepository $threadRepository,
        MessageThreadRecipientRepository $recipientRepository,
        EntityManagerInterface $entityManager,
        FileUploadService $fileUploadService,
        MessageEmailNotifier $emailNotifier,
        ValidatorInterface $validator,
        #[Target('app.message_body')] HtmlSanitizerInterface $sanitizer,
    ): JsonResponse {
        $thread = $threadRepository->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(MessageThreadVoter::REPLY, $thread);

        $body = trim((string) $request->request->get('body', ''));
        if ('' === $body) {
            return $this->json(['error' => 'body_required'], 422);
        }

        $attachmentsOrError = $this->validatedAttachments($request, $validator);
        if ($attachmentsOrError instanceof JsonResponse) {
            return $attachmentsOrError;
        }

        $sender = $this->currentUser();
        $message = new Message($thread, $sender, $sanitizer->sanitize($body));
        $entityManager->persist($message);

        $this->persistAttachments($message, $attachmentsOrError, $fileUploadService, $entityManager);

        $thread->touchLastMessageAt($message->getSentAt());

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

        return $this->json(['id' => $thread->getId()]);
    }

    private static function roleLabel(User $user): string
    {
        $roles = $user->getRoles();

        return match (true) {
            \in_array('ROLE_TEACHER', $roles, true) => 'Enseignant',
            \in_array('ROLE_STUDENT', $roles, true) => 'Élève',
            default => 'Personnel',
        };
    }

    /** @param list<UploadedFile> $files */
    private function persistAttachments(Message $message, array $files, FileUploadService $fileUploadService, EntityManagerInterface $entityManager): void
    {
        foreach ($files as $file) {
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
                $row->setLastReadAt(new \DateTimeImmutable());
            }
            $entityManager->persist($row);
        }
    }

    // Manual UploadedFile validation (rather than a Symfony Form's File/All constraint, which
    // needs a form field to attach to) - same maxSize/mimeTypes as MessageComposeType/
    // MessageReplyType's "attachments" field.
    /** @return list<UploadedFile>|JsonResponse */
    private function validatedAttachments(Request $request, ValidatorInterface $validator): array|JsonResponse
    {
        $files = $request->files->all('attachments');
        $constraint = new File(maxSize: FileUploadDefaults::MAX_SIZE, mimeTypes: self::ATTACHMENT_MIME_TYPES);

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                return $this->json(['error' => 'invalid_attachment'], 422);
            }

            $violations = $validator->validate($file, $constraint);
            if (\count($violations) > 0) {
                return $this->json(['error' => 'invalid_attachment', 'errors' => [(string) $violations->get(0)->getMessage()]], 422);
            }
        }

        return array_values($files);
    }

    /** @return array{id: int, subject: string, counterpart: string, snippet: string, lastMessageAt: string, unread: bool} */
    private function formatThreadRow(MessageThreadRecipient $recipient, string $folder, MessageRepository $messageRepository): array
    {
        $thread = $recipient->getThread();
        $latest = $messageRepository->findLatest($thread);
        $snippet = null !== $latest ? mb_strimwidth(trim(strip_tags($latest->getBody())), 0, 120, '…') : '';

        // Sent-folder rows have no single "other side" once the audience is broader than one
        // recipient - fall back to the subject itself rather than trying to list every recipient.
        $counterpart = MessageThreadRecipientRepository::FOLDER_SENT === $folder && $thread->getSender() === $recipient->getUser()
            ? $thread->getSubject()
            : ($thread->getSender()->getDisplayName() ?? $thread->getSender()->getUsername());

        return [
            'id' => $thread->getId(),
            'subject' => $thread->getSubject(),
            'counterpart' => $counterpart,
            'snippet' => $snippet,
            'lastMessageAt' => $thread->getLastMessageAt()->format(\DateTimeInterface::ATOM),
            'unread' => $recipient->isUnread(),
        ];
    }

    /** @return array{id: int, author: string, body: string, sentAt: string, attachments: list<array{label: string, url: string}>} */
    private function formatMessage(Message $message, FileUploadService $fileUploadService): array
    {
        return [
            'id' => $message->getId(),
            'author' => $message->getAuthor()->getDisplayName() ?? $message->getAuthor()->getUsername(),
            'body' => $message->getBody(),
            'sentAt' => $message->getSentAt()->format(\DateTimeInterface::ATOM),
            'attachments' => array_map(
                static fn (MessageAttachment $attachment): array => [
                    'label' => $attachment->getOriginalFilename(),
                    'url' => $fileUploadService->url($attachment->getStorageKey()),
                ],
                $message->getAttachments()->toArray(),
            ),
        ];
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
