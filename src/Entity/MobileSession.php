<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MobileApp;
use App\OAuth\OAuthSecret;
use App\Repository\MobileSessionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One phone signed in to the mobile API - what « Mon profil » lists under « Applications mobiles
 * connectées », and what its « Déconnecter » closes.
 *
 * The API's JWT lives an hour (LexikJWT's token_ttl) and is never stored here; what outlives it is
 * the refresh token, which this row holds as a selector and a hashed verifier (App\OAuth\OAuthSecret,
 * the Claude connector's split) and **rotates** at every exchange. Only two generations are kept -
 * the one the app should hold now, and the one it held before - because they are all the rules
 * need (App\Security\Mobile\MobileSessions::refresh()).
 *
 * The session lives as long as it is used: every exchange pushes `expiresAt` back by
 * IDLE_LIFETIME_DAYS. Revoked, never deleted by hand; the purge removes it once dead for a while.
 */
#[ORM\Entity(repositoryClass: MobileSessionRepository::class)]
#[ORM\Table(name: 'mobile_session')]
#[ORM\UniqueConstraint(name: 'mobile_session_current_selector_unique', columns: ['current_selector'])]
#[ORM\UniqueConstraint(name: 'mobile_session_previous_selector_unique', columns: ['previous_selector'])]
#[ORM\Index(name: 'mobile_session_expires_at_idx', columns: ['expires_at'])]
class MobileSession
{
    public const int IDLE_LIFETIME_DAYS = 30;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20, enumType: MobileApp::class)]
    private MobileApp $app;

    #[ORM\Column(name: 'current_selector', length: 16)]
    private string $currentSelector;

    #[ORM\Column(name: 'current_verifier_hash', length: 64)]
    private string $currentVerifierHash;

    #[ORM\Column(name: 'previous_selector', length: 16, nullable: true)]
    private ?string $previousSelector = null;

    #[ORM\Column(name: 'previous_verifier_hash', length: 64, nullable: true)]
    private ?string $previousVerifierHash = null;

    // When the previous generation was replaced - what the retry window is measured from.
    #[ORM\Column(name: 'rotated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $rotatedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'last_used_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastUsedAt;

    #[ORM\Column(name: 'last_used_ip', length: 45, nullable: true)]
    private ?string $lastUsedIp = null;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(User $user, MobileApp $app, OAuthSecret $refresh, \DateTimeImmutable $now, ?string $ip)
    {
        $this->user = $user;
        $this->app = $app;
        $this->currentSelector = $refresh->selector;
        $this->currentVerifierHash = $refresh->verifierHash;
        $this->createdAt = $now;
        $this->touch($now, $ip);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getApp(): MobileApp
    {
        return $this->app;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): \DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getLastUsedIp(): ?string
    {
        return $this->lastUsedIp;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isCurrent(string $selector): bool
    {
        return $selector === $this->currentSelector;
    }

    public function isPrevious(string $selector): bool
    {
        return null !== $this->previousSelector && $selector === $this->previousSelector;
    }

    public function verifiesCurrent(string $verifier): bool
    {
        return OAuthSecret::verifies($verifier, $this->currentVerifierHash);
    }

    public function verifiesPrevious(string $verifier): bool
    {
        return null !== $this->previousVerifierHash && OAuthSecret::verifies($verifier, $this->previousVerifierHash);
    }

    public function getRotatedAt(): ?\DateTimeImmutable
    {
        return $this->rotatedAt;
    }

    /** The ordinary exchange: the token the app just presented becomes the previous generation. */
    public function rotate(OAuthSecret $next, \DateTimeImmutable $now, ?string $ip): static
    {
        $this->previousSelector = $this->currentSelector;
        $this->previousVerifierHash = $this->currentVerifierHash;
        $this->rotatedAt = $now;
        $this->replaceCurrent($next, $now, $ip);

        return $this;
    }

    /**
     * The retried exchange: the app presented the previous generation again, because the answer
     * carrying the current one never reached it. That current one was never received, so it is
     * dropped and replaced; the previous generation and its window stay as they were, so a second
     * lost answer inside the window is forgiven the same way.
     */
    public function replaceCurrent(OAuthSecret $next, \DateTimeImmutable $now, ?string $ip): static
    {
        $this->currentSelector = $next->selector;
        $this->currentVerifierHash = $next->verifierHash;
        $this->touch($now, $ip);

        return $this;
    }

    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
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

    private function touch(\DateTimeImmutable $now, ?string $ip): void
    {
        $this->lastUsedAt = $now;
        $this->lastUsedIp = $ip;
        $this->expiresAt = $now->modify(\sprintf('+%d days', self::IDLE_LIFETIME_DAYS));
    }
}
