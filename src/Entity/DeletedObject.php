<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DeletedObjectRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One object whose bytes are due to leave the bucket (design/validated/object-deletion.md).
 *
 * Since deletion became deferred platform-wide, nothing in the application removes bytes any more:
 * App\Service\ObjectStore writes a row here instead, and App\Command\PurgeUploadsCommand is the only
 * thing that reads it and actually deletes. A file therefore survives its deletion by the retention
 * window of its origin - thirty days for a teacher's course material - which is what gives the file
 * library a corbeille and what makes a mistaken delete recoverable at all.
 *
 * Two columns carry more than they look:
 *
 * - **`storageKey` is the full key, environment prefix included** ("dev/avatars/12-…​.png"), because
 *   that is what the purge hands to S3. The three services that delete speak in keys *without* the
 *   prefix - two apply it by hand, one gets it from Flysystem - so ObjectStore is where the two
 *   conventions meet, and it meets them here rather than in the purge.
 * - **`origin` is not decoration.** It is what lets the purge apply hours to an abandoned import
 *   batch and thirty days to a teacher's deleted course material, and what makes this table
 *   readable when something has gone wrong.
 *
 * `purgedAt` stays null until the bytes are genuinely gone. A row marked purged while the object
 * remains would turn a permission problem into a permanent, invisible leak - which is the failure
 * the whole design exists to avoid - so the purge stamps it after the delete succeeds, never before.
 */
#[ORM\Entity(repositoryClass: DeletedObjectRepository::class)]
#[ORM\Table(name: 'deleted_object')]
#[ORM\UniqueConstraint(name: 'uniq_deleted_object_storage_key', columns: ['storage_key'])]
#[ORM\Index(name: 'idx_deleted_object_pending', columns: ['purged_at', 'deleted_at'])]
class DeletedObject
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(name: 'storage_key', length: 255)]
    private string $storageKey;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $deletedAt;

    // Null for a system deletion - an abandoned import batch, a staged upload nobody claimed, a
    // command. SET NULL rather than CASCADE: the row outlives the account that asked for it, and
    // losing the trace of a deletion because its author left the school is the wrong trade.
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'deleted_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $deletedBy = null;

    #[ORM\Column(length: 64)]
    private string $origin;

    #[ORM\Column(name: 'purged_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $purgedAt = null;

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private int $attempts = 0;

    #[ORM\Column(name: 'last_error', length: 255, nullable: true)]
    private ?string $lastError = null;

    public function __construct(string $storageKey, string $origin, ?\DateTimeImmutable $deletedAt = null)
    {
        $this->storageKey = $storageKey;
        $this->origin = $origin;
        $this->deletedAt = $deletedAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function getDeletedAt(): \DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(\DateTimeImmutable $deletedAt): self
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }

    public function getDeletedBy(): ?User
    {
        return $this->deletedBy;
    }

    public function setDeletedBy(?User $deletedBy): self
    {
        $this->deletedBy = $deletedBy;

        return $this;
    }

    public function getOrigin(): string
    {
        return $this->origin;
    }

    public function getPurgedAt(): ?\DateTimeImmutable
    {
        return $this->purgedAt;
    }

    public function setPurgedAt(?\DateTimeImmutable $purgedAt): self
    {
        $this->purgedAt = $purgedAt;

        return $this;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function recordFailure(string $message): self
    {
        ++$this->attempts;
        // The column is 255 and an AWS exception message is routinely longer; the tail is the part
        // that repeats, the head is the part that names the operation.
        $this->lastError = mb_substr($message, 0, 255);

        return $this;
    }
}
