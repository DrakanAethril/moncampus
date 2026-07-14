<?php

namespace App\Entity;

use App\Repository\MessageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One post in a MessageThread's conversation - the thread's first Message is its opening post;
 * later ones only ever get created for a 1:1-shaped thread (see MessageThread's docblock), never
 * an announcement-shaped one. $body is sanitized server-side before persisting (see
 * config/packages/html_sanitizer.yaml's "app.message_body" sanitizer) since it's HugeRTE-authored
 * HTML rendered back to other users.
 */
#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Table(name: 'message')]
class Message
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MessageThread::class)]
    #[ORM\JoinColumn(name: 'thread_id', nullable: false)]
    private ?MessageThread $thread = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', nullable: false)]
    private ?User $author = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $body = '';

    #[ORM\Column(name: 'sent_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $sentAt;

    /** @var Collection<int, MessageAttachment> */
    #[ORM\OneToMany(mappedBy: 'message', targetEntity: MessageAttachment::class, orphanRemoval: true)]
    private Collection $attachments;

    public function __construct(MessageThread $thread, User $author, string $body)
    {
        $this->thread = $thread;
        $this->author = $author;
        $this->body = $body;
        $this->sentAt = new \DateTimeImmutable();
        $this->attachments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getThread(): ?MessageThread
    {
        return $this->thread;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }

    /** @return Collection<int, MessageAttachment> */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }
}
