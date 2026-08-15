<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PlatformActivityType;
use App\Repository\PlatformActivityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Timestamped log of the platform - everything that is not the UFA, starting with the logins.
 * No foreign key towards the UFA: the separation from App\Entity\UfaActivity is the very point of
 * this table, and it holds because nothing in the schema links them.
 *
 * Same rules as UfaActivity: append-only, written after the business flush, sentence composed at
 * display time from $type and $payload.
 *
 * $ipAddress/$userAgent only exist on this side: this is the log we will open the day someone asks
 * where a person logged in from.
 *
 * Unlike UfaActivity, purged beyond 12 months (App\Command\PurgePlatformActivityCommand): one row
 * per login is what makes the table grow.
 */
#[ORM\Entity(repositoryClass: PlatformActivityRepository::class)]
#[ORM\Table(name: 'platform_activity')]
#[ORM\Index(name: 'idx_platform_activity_occurred', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_platform_activity_actor', columns: ['actor_id', 'occurred_at'])]
class PlatformActivity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'occurred_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(length: 60, enumType: PlatformActivityType::class)]
    private PlatformActivityType $type;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'actor_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    // 45 characters: the length of an IPv6 address in full notation.
    #[ORM\Column(name: 'ip_address', length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(name: 'user_agent', length: 255, nullable: true)]
    private ?string $userAgent = null;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    /** @param array<string, string> $payload */
    public function __construct(PlatformActivityType $type, ?User $actor, array $payload = [])
    {
        $this->type = $type;
        $this->actor = $actor;
        $this->payload = $payload;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getType(): PlatformActivityType
    {
        return $this->type;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;

        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        // Truncated rather than refused: an exotic User-Agent must not make an otherwise valid login
        // fail.
        $this->userAgent = null !== $userAgent ? mb_substr($userAgent, 0, 255) : null;

        return $this;
    }

    /** @return array<string, string> */
    public function getPayload(): array
    {
        return $this->payload;
    }
}
