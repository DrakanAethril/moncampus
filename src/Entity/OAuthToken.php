<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\OAuthTokenKind;
use App\Repository\OAuthTokenRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An access or refresh token of the Claude connector, stored as selector + hashed verifier like
 * App\Entity\JobboardToken: nothing readable in the database opens anything.
 *
 * A refresh token is **rotated**, never reused: exchanging it stamps `rotatedAt` and issues a new
 * pair. A rotated refresh token presented again means two parties hold it, and App\OAuth\TokenIssuer
 * answers that by revoking the whole grant (RFC 9700 § 4.14.2).
 */
#[ORM\Entity(repositoryClass: OAuthTokenRepository::class)]
#[ORM\Table(name: 'oauth_token')]
#[ORM\UniqueConstraint(name: 'uniq_oauth_token_selector', columns: ['selector'])]
#[ORM\Index(name: 'idx_oauth_token_expires_at', columns: ['expires_at'])]
class OAuthToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OAuthGrant::class)]
    #[ORM\JoinColumn(name: 'grant_id', nullable: false, onDelete: 'CASCADE')]
    private OAuthGrant $grant;

    #[ORM\Column(length: 10, enumType: OAuthTokenKind::class)]
    private OAuthTokenKind $kind;

    #[ORM\Column(length: 16)]
    private string $selector;

    #[ORM\Column(name: 'verifier_hash', length: 64)]
    private string $verifierHash;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'rotated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $rotatedAt = null;

    public function __construct(
        OAuthGrant $grant,
        OAuthTokenKind $kind,
        string $selector,
        string $verifierHash,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $expiresAt,
    ) {
        $this->grant = $grant;
        $this->kind = $kind;
        $this->selector = $selector;
        $this->verifierHash = $verifierHash;
        $this->createdAt = $createdAt;
        $this->expiresAt = $expiresAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGrant(): OAuthGrant
    {
        return $this->grant;
    }

    public function getKind(): OAuthTokenKind
    {
        return $this->kind;
    }

    public function getSelector(): string
    {
        return $this->selector;
    }

    public function getVerifierHash(): string
    {
        return $this->verifierHash;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function getRotatedAt(): ?\DateTimeImmutable
    {
        return $this->rotatedAt;
    }

    public function isRotated(): bool
    {
        return null !== $this->rotatedAt;
    }

    public function markRotated(\DateTimeImmutable $now): static
    {
        $this->rotatedAt ??= $now;

        return $this;
    }
}
