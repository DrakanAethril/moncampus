<?php

namespace App\Entity;

use App\Repository\MessageThreadRecipientRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One participant's private view of a MessageThread - the fan-out row that carries per-user state
 * (read/archived/deleted). One row per participant per thread, the sender included: this is what
 * makes Inbox/Sent a plain filter (Inbox = rows where user != thread.sender, Sent = rows where
 * user == thread.sender) instead of two separate mechanisms - see
 * design/validated/internal-messaging.md.
 *
 * Deletion is always this row's own $deletedAt, never a real delete of the Message/MessageThread -
 * scoped strictly to that one participant's own copy, retained in the database for logging
 * purposes even though it disappears from the platform entirely for them. If a new Message later
 * arrives on the thread, $deletedAt is reset to null (see App\Controller\MessageController) so
 * genuinely new content resurfaces rather than staying hidden forever.
 */
#[ORM\Entity(repositoryClass: MessageThreadRecipientRepository::class)]
#[ORM\Table(name: 'message_thread_recipient')]
#[ORM\UniqueConstraint(name: 'uniq_message_thread_recipient', columns: ['thread_id', 'user_id'])]
class MessageThreadRecipient
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MessageThread::class)]
    #[ORM\JoinColumn(name: 'thread_id', nullable: false)]
    private ?MessageThread $thread = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false)]
    private ?User $user = null;

    #[ORM\Column(name: 'last_read_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastReadAt = null;

    #[ORM\Column(name: 'archived_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct(MessageThread $thread, User $user)
    {
        $this->thread = $thread;
        $this->user = $user;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getThread(): ?MessageThread
    {
        return $this->thread;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getLastReadAt(): ?\DateTimeImmutable
    {
        return $this->lastReadAt;
    }

    public function setLastReadAt(?\DateTimeImmutable $lastReadAt): static
    {
        $this->lastReadAt = $lastReadAt;

        return $this;
    }

    public function isUnread(): bool
    {
        return null === $this->lastReadAt || $this->lastReadAt < $this->thread->getLastMessageAt();
    }

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function setArchivedAt(?\DateTimeImmutable $archivedAt): static
    {
        $this->archivedAt = $archivedAt;

        return $this;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?\DateTimeImmutable $deletedAt): static
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }
}
