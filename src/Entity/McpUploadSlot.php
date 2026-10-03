<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\McpUploadSlotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One address the Claude connector hands out for a single file (`file_upload_url`): Claude's own
 * sandbox then sends the bytes there with a plain `curl -X PUT --data-binary`, instead of writing
 * them as base64 one token at a time.
 *
 * Everything the file will become is decided when the address is made, and bound to it: the
 * teacher, the connection it was asked through, the folder, the name and the exact size - plus,
 * when Claude gave one, the SHA-256 the bytes must match. The PUT can change none of it.
 *
 * The address carries a secret stored as selector + hashed verifier (App\OAuth\OAuthSecret), like
 * the connector's tokens. It serves **once** - `usedAt` is stamped by an atomic UPDATE before a byte
 * is read, so two PUTs racing on the same address cannot both get through - and expires after
 * App\Mcp\Tool\FileUploadUrlTool::LIFETIME_MINUTES. A refused send spends it too: Claude asks for
 * another, which re-checks what may have changed in between (quota, folder).
 */
#[ORM\Entity(repositoryClass: McpUploadSlotRepository::class)]
#[ORM\Table(name: 'mcp_upload_slot')]
#[ORM\UniqueConstraint(name: 'uniq_mcp_upload_slot_selector', columns: ['selector'])]
#[ORM\Index(name: 'idx_mcp_upload_slot_expires_at', columns: ['expires_at'])]
class McpUploadSlot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Revoking the connection from « Mon profil » closes its pending addresses at once. */
    #[ORM\ManyToOne(targetEntity: OAuthGrant::class)]
    #[ORM\JoinColumn(name: 'grant_id', nullable: false, onDelete: 'CASCADE')]
    private OAuthGrant $grant;

    /** A folder deleted meanwhile takes the address with it, rather than sending the file to the root. */
    #[ORM\ManyToOne(targetEntity: FileLibraryNode::class)]
    #[ORM\JoinColumn(name: 'folder_id', nullable: true, onDelete: 'CASCADE')]
    private ?FileLibraryNode $folder;

    #[ORM\Column(length: 255)]
    private string $name;

    /** The exact length the body must have - a shorter one is a transfer cut short, not a file. */
    #[ORM\Column(name: 'size_bytes', type: Types::BIGINT)]
    private int $sizeBytes;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sha256;

    #[ORM\Column(length: 16)]
    private string $selector;

    #[ORM\Column(name: 'verifier_hash', length: 64)]
    private string $verifierHash;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    /** The library file the send produced; null while unused, and for a refused send. */
    #[ORM\ManyToOne(targetEntity: FileLibraryNode::class)]
    #[ORM\JoinColumn(name: 'file_id', nullable: true, onDelete: 'SET NULL')]
    private ?FileLibraryNode $file = null;

    public function __construct(
        OAuthGrant $grant,
        ?FileLibraryNode $folder,
        string $name,
        int $sizeBytes,
        ?string $sha256,
        string $selector,
        string $verifierHash,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $expiresAt,
    ) {
        $this->grant = $grant;
        $this->folder = $folder;
        $this->name = $name;
        $this->sizeBytes = $sizeBytes;
        $this->sha256 = null === $sha256 ? null : strtolower($sha256);
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

    public function getUser(): User
    {
        return $this->grant->getUser();
    }

    public function getFolder(): ?FileLibraryNode
    {
        return $this->folder;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function getSha256(): ?string
    {
        return $this->sha256;
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

    public function getFile(): ?FileLibraryNode
    {
        return $this->file;
    }

    public function setFile(FileLibraryNode $file): void
    {
        $this->file = $file;
    }
}
