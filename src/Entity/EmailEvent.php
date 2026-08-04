<?php

namespace App\Entity;

use App\Repository\EmailEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un événement d'acheminement SES (Send, Delivery, Bounce, Complaint, Reject, DeliveryDelay)
 * consommé depuis la file « events », conservé brut en plus du statut agrégé porté par
 * App\Entity\EmailMessage::$deliveryStatus.
 *
 * Deux raisons de garder l'historique plutôt que le seul dernier état : un rebond contient le
 * motif exact du refus, qu'on veut pouvoir montrer à l'élève ; et les événements n'arrivent pas
 * forcément dans l'ordre chronologique, donc il faut pouvoir recalculer le statut plutôt que de
 * l'écraser aveuglément.
 *
 * `message_id` est une chaîne, pas une relation : un événement peut précéder l'écriture du
 * message auquel il se rapporte, ou concerner un envoi purgé. La corrélation se fait à la
 * lecture.
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

    /** Le type tel que SES l'écrit (`Delivery`, `Bounce`, ...), sans normalisation. */
    #[ORM\Column(name: 'event_type', length: 32)]
    private string $eventType;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    /**
     * L'horodatage porté par l'événement SES lui-même, pas celui de sa consommation. C'est lui
     * qui participe à la clé d'unicité : SQS pouvant relivrer, c'est le triplet
     * (message, type, instant) qui identifie un événement.
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
