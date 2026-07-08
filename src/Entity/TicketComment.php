<?php

namespace App\Entity;

use App\Repository\TicketCommentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One entry in a ticket's conversation thread - either a human reply (from the reporter or a
 * handler) or a system-generated log line ("Status changed to Awaiting Info by X"). Using the
 * same table for both means the thread doubles as the ticket's full status/assignment history,
 * without a separate audit table - same idea as LaptopLoan rows being the lending history.
 */
#[ORM\Entity(repositoryClass: TicketCommentRepository::class)]
#[ORM\Table(name: 'ticket_comment')]
class TicketComment
{
    public const VISIBILITY_PUBLIC = 'public';
    public const VISIBILITY_INTERNAL = 'internal';

    /** @var list<string> */
    public const VISIBILITIES = [self::VISIBILITY_PUBLIC, self::VISIBILITY_INTERNAL];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'ticket_id', nullable: false)]
    private ?Ticket $ticket = null;

    // Who wrote it - for a system-generated entry, this is whoever triggered the change (e.g.
    // the handler who changed the status), not a system/service account.
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', nullable: false)]
    private ?User $author = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $body = '';

    #[ORM\Column(length: 255)]
    #[Assert\Choice(choices: self::VISIBILITIES)]
    private string $visibility = self::VISIBILITY_PUBLIC;

    #[ORM\Column(name: 'is_system_generated')]
    private bool $isSystemGenerated = false;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    public function __construct(Ticket $ticket, User $author, string $body)
    {
        $this->ticket = $ticket;
        $this->author = $author;
        $this->body = $body;
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTicket(): ?Ticket
    {
        return $this->ticket;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function getVisibility(): string
    {
        return $this->visibility;
    }

    public function setVisibility(string $visibility): static
    {
        $this->visibility = $visibility;

        return $this;
    }

    public function isInternal(): bool
    {
        return self::VISIBILITY_INTERNAL === $this->visibility;
    }

    public function isSystemGenerated(): bool
    {
        return $this->isSystemGenerated;
    }

    public function setIsSystemGenerated(bool $isSystemGenerated): static
    {
        $this->isSystemGenerated = $isSystemGenerated;

        return $this;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }
}
