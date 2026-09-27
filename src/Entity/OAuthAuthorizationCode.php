<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OAuthAuthorizationCodeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The one-minute, single-use code a consent hands back to the client through its redirect URI, and
 * that the token endpoint exchanges for a token pair (App\OAuth\TokenIssuer).
 *
 * It carries everything the exchange must match again: the redirect URI it was sent to, the PKCE
 * challenge the client committed to before the user ever saw the consent screen, and the resource
 * it was asked for. Stored as selector + hashed verifier, like every secret on this platform.
 */
#[ORM\Entity(repositoryClass: OAuthAuthorizationCodeRepository::class)]
#[ORM\Table(name: 'oauth_authorization_code')]
#[ORM\UniqueConstraint(name: 'uniq_oauth_authorization_code_selector', columns: ['selector'])]
class OAuthAuthorizationCode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OAuthGrant::class)]
    #[ORM\JoinColumn(name: 'grant_id', nullable: false, onDelete: 'CASCADE')]
    private OAuthGrant $grant;

    #[ORM\Column(length: 16)]
    private string $selector;

    #[ORM\Column(name: 'verifier_hash', length: 64)]
    private string $verifierHash;

    #[ORM\Column(name: 'redirect_uri', length: 2048)]
    private string $redirectUri;

    #[ORM\Column(name: 'code_challenge', length: 128)]
    private string $codeChallenge;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $resource;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    public function __construct(
        OAuthGrant $grant,
        string $selector,
        string $verifierHash,
        string $redirectUri,
        string $codeChallenge,
        ?string $resource,
        \DateTimeImmutable $expiresAt,
    ) {
        $this->grant = $grant;
        $this->selector = $selector;
        $this->verifierHash = $verifierHash;
        $this->redirectUri = $redirectUri;
        $this->codeChallenge = $codeChallenge;
        $this->resource = $resource;
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

    public function getSelector(): string
    {
        return $this->selector;
    }

    public function getVerifierHash(): string
    {
        return $this->verifierHash;
    }

    public function getRedirectUri(): string
    {
        return $this->redirectUri;
    }

    public function getCodeChallenge(): string
    {
        return $this->codeChallenge;
    }

    public function getResource(): ?string
    {
        return $this->resource;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function isUsed(): bool
    {
        return null !== $this->usedAt;
    }

    public function markUsed(\DateTimeImmutable $now): static
    {
        $this->usedAt ??= $now;

        return $this;
    }
}
