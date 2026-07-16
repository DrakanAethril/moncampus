<?php

namespace App\Entity;

use App\Repository\MagicLoginTokenRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

// Passwordless "magic link" login (App\Service\MagicLoginService). Deliberately its own entity
// rather than token/expiry columns on User (the pattern User::$contactEmailToken uses) - unlike
// that flow, this token IS full account access, and it must be resolvable to a User before
// anyone is authenticated, so it needs a global (not per-user-scoped) lookup. Selector/verifier
// split (like Symfony's own remember-me persistent tokens): $selector is looked up directly
// (indexed, unique), $verifierHash is only compared with hash_equals() after that lookup - the
// secret half is never stored in plaintext, since a DB read of this table would otherwise be a
// direct account takeover for every row.
#[ORM\Entity(repositoryClass: MagicLoginTokenRepository::class)]
#[ORM\Table(name: 'magic_login_token')]
#[ORM\UniqueConstraint(name: 'uniq_magic_login_token_selector', columns: ['selector'])]
class MagicLoginToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 32)]
    private string $selector;

    #[ORM\Column(name: 'verifier_hash', length: 64)]
    private string $verifierHash;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    // Set the instant the link is actually followed through to a session (App\Security\
    // MagicLinkAuthenticator) - null means still pending, whether or not it has expired.
    #[ORM\Column(name: 'used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(name: 'request_ip', length: 45, nullable: true)]
    private ?string $requestIp = null;

    public function __construct(User $user, string $selector, string $verifierHash, \DateTimeImmutable $expiresAt, ?string $requestIp)
    {
        $this->user = $user;
        $this->selector = $selector;
        $this->verifierHash = $verifierHash;
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $expiresAt;
        $this->requestIp = $requestIp;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getSelector(): string
    {
        return $this->selector;
    }

    public function getVerifierHash(): string
    {
        return $this->verifierHash;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function isUsed(): bool
    {
        return null !== $this->usedAt;
    }

    public function markUsed(): void
    {
        $this->usedAt = new \DateTimeImmutable();
    }
}
