<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OAuthGrantRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One user's consent to one client - what « Mon profil » lists as a connection of the Claude
 * connector, and what « Révoquer » closes.
 *
 * Every code and every token hangs off a grant, so revoking it closes all of them at once, including
 * the refresh token the client would otherwise keep renewing. It is **revoked, never deleted**: it
 * is the trace of who created what through the connector (App\Enum\PlatformActivityType).
 */
#[ORM\Entity(repositoryClass: OAuthGrantRepository::class)]
#[ORM\Table(name: 'oauth_grant')]
class OAuthGrant
{
    /**
     * How stale `lastUsedAt` may get before a request writes it again. The access token is checked
     * on every call of the connector, and a write per call would be a write per tool Claude runs.
     */
    public const int LAST_USED_RESOLUTION_SECONDS = 300;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: OAuthClient::class)]
    #[ORM\JoinColumn(name: 'client_id', nullable: false, onDelete: 'CASCADE')]
    private OAuthClient $client;

    #[ORM\Column(length: 255)]
    private string $scope;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'last_used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(name: 'last_used_ip', length: 45, nullable: true)]
    private ?string $lastUsedIp = null;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(User $user, OAuthClient $client, string $scope, \DateTimeImmutable $createdAt)
    {
        $this->user = $user;
        $this->client = $client;
        $this->scope = $scope;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getClient(): OAuthClient
    {
        return $this->client;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getLastUsedIp(): ?string
    {
        return $this->lastUsedIp;
    }

    /**
     * @return bool whether anything changed - the caller flushes only then
     */
    public function markUsed(\DateTimeImmutable $now, ?string $ip): bool
    {
        if (null !== $this->lastUsedAt
            && $now->getTimestamp() - $this->lastUsedAt->getTimestamp() < self::LAST_USED_RESOLUTION_SECONDS
            && $ip === $this->lastUsedIp) {
            return false;
        }

        $this->lastUsedAt = $now;
        $this->lastUsedIp = $ip;

        return true;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function revoke(\DateTimeImmutable $now): static
    {
        $this->revokedAt ??= $now;

        return $this;
    }
}
