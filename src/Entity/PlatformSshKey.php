<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlatformSshKeyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The key pair MonCampus uses to get inside the machines it created.
 *
 * One row is active at a time; the others are the history of rotations. Rotation is the reason this
 * is a table rather than an environment variable: replacing a key means **posting the new one,
 * verifying it works, and only then removing the old** - and doing that across two dozen machines
 * takes long enough that both keys have to exist at once. A single value in the environment has no
 * room for the overlap, and the order cannot be got wrong safely without it.
 *
 * The private half is sealed by App\Service\Crypto\SecretBox, like every other secret here. The
 * public half is not: it is meant to be copied into machines, and cloud-init writes it into
 * authorized_keys at the first boot.
 *
 * Nothing exposes the private key. `__debugInfo()` masks it, and the one place it is opened is
 * App\Service\Guest\PlatformKeyProvider.
 */
#[ORM\Entity(repositoryClass: PlatformSshKeyRepository::class)]
#[ORM\Table(name: 'platform_ssh_key')]
class PlatformSshKey
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** `ssh-ed25519 AAAA… moncampus@platform` - public material, stored as such. */
    #[ORM\Column(name: 'public_key', type: Types::TEXT)]
    private string $publicKey;

    #[ORM\Column(name: 'private_key_cipher', type: Types::TEXT)]
    private string $privateKeyCipher;

    /** SHA-256 of the public key, so two keys can be told apart in a log without printing either. */
    #[ORM\Column(length: 64)]
    private string $fingerprint;

    /**
     * Exactly one row is active. The previous one lives on until a rotation is finished, which is
     * the whole point - see the class docblock.
     */
    #[ORM\Column(name: 'is_active')]
    private bool $active = true;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'retired_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $retiredAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    public function __construct(string $publicKey, string $privateKeyCipher, string $fingerprint)
    {
        $this->publicKey = $publicKey;
        $this->privateKeyCipher = $privateKeyCipher;
        $this->fingerprint = $fingerprint;
        $this->creationDate = new \DateTimeImmutable();
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'fingerprint' => $this->fingerprint,
            'active' => $this->active,
            'privateKeyCipher' => '***',
        ];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    /** The sealed envelope, never the key. Only PlatformKeyProvider opens it. */
    public function getPrivateKeyCipher(): string
    {
        return $this->privateKeyCipher;
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function retire(): static
    {
        $this->active = false;
        $this->retiredAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    public function getRetiredAt(): ?\DateTimeImmutable
    {
        return $this->retiredAt;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }
}
