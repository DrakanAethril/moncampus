<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\JobboardTokenRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An ingestion key handed to one collecting agent, for one filière.
 *
 * **The filière lives here and nowhere else.** It is never in a URL, never in a payload: an agent
 * files offers into the filière of the key it presents, which makes "wrong filière" an
 * impossibility rather than a validation rule.
 *
 * The secret is stored the way App\Entity\MagicLoginToken stores its own: a `selector` looked up
 * directly (indexed, unique) and a `verifierHash` compared with hash_equals() only after that
 * lookup - so the lookup itself is not a timing oracle, and nothing readable in the database opens
 * anything. It is shown once, at creation, and never again: this platform does not read passwords
 * back.
 *
 * A key is **revoked, never deleted**: it is the trace of what pushed the offers already stored.
 */
#[ORM\Entity(repositoryClass: JobboardTokenRepository::class)]
#[ORM\Table(name: 'jobboard_token')]
#[ORM\UniqueConstraint(name: 'uniq_jobboard_token_selector', columns: ['selector'])]
class JobboardToken
{
    /** The scheme of the secret handed out: `mcjb_<selector>_<verifier>`. */
    public const string PREFIX = 'mcjb';

    public const int SELECTOR_LENGTH = 12;

    public const int VERIFIER_LENGTH = 32;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $label;

    #[ORM\ManyToOne(targetEntity: Section::class)]
    #[ORM\JoinColumn(name: 'section_id', nullable: false, onDelete: 'CASCADE')]
    private Section $section;

    #[ORM\Column(length: 16)]
    private string $selector;

    #[ORM\Column(name: 'verifier_hash', length: 64)]
    private string $verifierHash;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    // What the screen answers « la veille tourne-t-elle ? » with. Written on every accepted call,
    // which is one write per request on a path that runs a few times a day - not a concern.
    #[ORM\Column(name: 'last_used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(name: 'last_used_ip', length: 45, nullable: true)]
    private ?string $lastUsedIp = null;

    public function __construct(string $label, Section $section, string $selector, string $verifierHash, ?User $createdBy)
    {
        $this->label = $label;
        $this->section = $section;
        $this->selector = $selector;
        $this->verifierHash = $verifierHash;
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getSection(): Section
    {
        return $this->section;
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

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function revoke(): static
    {
        $this->revokedAt ??= new \DateTimeImmutable();

        return $this;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getLastUsedIp(): ?string
    {
        return $this->lastUsedIp;
    }

    public function markUsed(?string $ip): static
    {
        $this->lastUsedAt = new \DateTimeImmutable();
        $this->lastUsedIp = $ip;

        return $this;
    }
}
