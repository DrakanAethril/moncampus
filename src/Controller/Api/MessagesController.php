<?php

namespace App\Controller\Api;

use App\Entity\Message;
use App\Entity\MessageAttachment;
use App\Entity\MessageThread;
use App\Entity\MessageThreadRecipient;
use App\Entity\User;
use App\Repository\MessageRepository;
use App\Repository\MessageThreadRecipientRepository;
use App\Repository\MessageThreadRepository;
use App\Security\Voter\MessageThreadVoter;
use App\Service\FileUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only mobile counterpart to MessageController - same MessageThread/Message/
 * MessageThreadRecipient model and folder convention (see design/validated/internal-messaging.md
 * and MessageThreadRecipientRepository::FOLDER_*), just plain JSON instead of the web inbox's
 * DataTables-shaped /messages/data feed (which embeds pre-rendered HTML strings, unusable as-is
 * for a mobile client). Composing/replying isn't exposed here yet - browsing only, for now.
 */
class MessagesController extends AbstractController
{
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
    public function show(int $id, MessageThreadRepository $threadRepository, MessageRepository $messageRepository, MessageThreadRecipientRepository $recipientRepository, EntityManagerInterface $entityManager, FileUploadService $fileUploadService): JsonResponse
    {
        $thread = $threadRepository->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(MessageThreadVoter::VIEW, $thread);

        $user = $this->currentUser();
        $recipientRow = $recipientRepository->findOneForUserAndThread($user, $thread) ?? throw $this->createNotFoundException();

        // Marking as read on GET matches the web's show() action - a mobile client fetching the
        // thread body is the same "the user opened it" signal a page load is on the web.
        $recipientRow->setLastReadAt(new \DateTimeImmutable());
        $entityManager->flush();

        return $this->json([
            'id' => $thread->getId(),
            'subject' => $thread->getSubject(),
            'canReply' => $this->isGranted(MessageThreadVoter::REPLY, $thread),
            'messages' => array_map(fn (Message $message): array => $this->formatMessage($message, $fileUploadService), $messageRepository->findForThread($thread)),
        ]);
    }

    #[Route(path: '/api/messages/unread-count', name: 'api_messages_unread_count', methods: ['GET'])]
    public function unreadCount(MessageThreadRecipientRepository $recipientRepository): JsonResponse
    {
        return $this->json(['count' => $recipientRepository->countUnreadForUser($this->currentUser())]);
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
