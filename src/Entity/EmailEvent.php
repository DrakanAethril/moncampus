<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EmailEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An SES delivery event (Send, Delivery, Bounce, Complaint, Reject, DeliveryDelay) consumed from the
 * « events » queue, kept raw in addition to the aggregated status carried by
 * App\Entity\EmailMessage::$deliveryStatus.
 *
 * Two reasons to keep the history rather than the last state alone: a bounce contains the exact
 * reason for the refusal, which we want to be able to show the student; and events do not
 * necessarily arrive in chronological order, so the status has to be recomputable rather than blindly
 * overwritten.
 *
 * `message_id` is a string, not a relation: an event may precede the writing of the message it
 * relates to, or concern a purged send. The correlation is made on reading.
 */
#[ORM\Entity(repositoryClass: EmailEventRepository::class)]
#[ORM\Table(name: 'email_event')]
#[ORM\UniqueConstraint(name: 'uniq_email_event_dedup', columns: ['message_id', 'event_type', 'occurred_at'])]
#[ORM\Index(name: 'idx_email_event_message_id', columns: ['message_id'])]
class EmailEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'message_id', length: 255)]
    private string $messageId;

    /** The type as SES writes it (`Delivery`, `Bounce`, ...), with no normalisation. */
    #[ORM\Column(name: 'event_type', length: 32)]
    private string $eventType;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    /**
     * The timestamp carried by the SES event itself, not that of its consumption. It is the one that
     * takes part in the uniqueness key: SQS being able to redeliver, it is the (message, type,
     * instant) triple that identifies an event.
     */
    #[ORM\Column(name: 'occurred_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMessageId(): string
    {
        return $this->messageId;
    }

    public function setMessageId(string $messageId): static
    {
        $this->messageId = $messageId;

        return $this;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function setEventType(string $eventType): static
    {
        $this->eventType = $eventType;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /** @param array<string, mixed> $payload */
    public function setPayload(array $payload): static
    {
        $this->payload = $payload;

        return $this;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(\DateTimeImmutable $occurredAt): static
    {
        $this->occurredAt = $occurredAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
