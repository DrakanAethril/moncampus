<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FileLibraryNodeType;
use App\Repository\FileLibraryNodeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One node of somebody's file library - a folder or a file, in a single table
 * (design/validated/file-library.md).
 *
 * The library is **personal**: `owner` is not a scope to be widened later, it is the access model.
 * Nobody reads a colleague's library, and App\Security\Voter\FileLibraryVoter is where that is said
 * once.
 *
 * Four columns carry more than they look:
 *
 * - **`path`** holds the ancestors' ids, '/12/48/', so "everything under folder 48" is one
 *   `LIKE '/12/48/%'`. VARCHAR(768) is the largest InnoDB indexes under utf8mb4. Moving a subtree
 *   rewrites `path` and `depth` on the node and its descendants in a single UPDATE - the only
 *   operation in this feature that touches more than one row.
 * - **`name` is unique among siblings, and no index enforces it**: MySQL treats every NULL as
 *   distinct, so UNIQUE (owner, parent, name) would constrain nothing at root level - the level a
 *   user meets first. App\Service\FileLibraryTree is the authority; the index below is a lookup one.
 * - **`storageKey` is UNIQUE**, and it is what makes *Remplacer* keep every link: replacing a file
 *   keeps the node id and writes a new object, so the eleven assignments pointing at this node serve
 *   the corrected PDF.
 * - **`deletedAt` is the corbeille** (design/validated/object-deletion.md). A deleted file leaves the
 *   tree, the search, the picker **and the quota sum** at once; its bytes follow thirty days later.
 *
 * There is deliberately **no `position`**: the library sorts by name, date or size at the reader's
 * choice, like every file manager. Dropping position also drops the reorder drag, and the only drag
 * left is *move into a folder*.
 */
#[ORM\Entity(repositoryClass: FileLibraryNodeRepository::class)]
#[ORM\Table(name: 'file_library_node')]
#[ORM\Index(name: 'idx_flib_owner_parent', columns: ['owner_id', 'parent_id'])]
#[ORM\Index(name: 'idx_flib_owner_type', columns: ['owner_id', 'type'])]
#[ORM\Index(name: 'idx_flib_path', columns: ['path'])]
#[ORM\UniqueConstraint(name: 'uniq_flib_storage_key', columns: ['storage_key'])]
class FileLibraryNode
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(name: 'parent_id', nullable: true, onDelete: 'CASCADE')]
    private ?self $parent = null;

    /** @var Collection<int, self> */
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)]
    private Collection $children;

    #[ORM\Column(enumType: FileLibraryNodeType::class, length: 16)]
    private FileLibraryNodeType $type;

    /** The display name, extension included for a file. */
    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 768)]
    private string $path = '/';

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $depth = 0;

    // --- File only, all null on a folder ----------------------------------------------------

    #[ORM\Column(name: 'storage_key', length: 255, nullable: true)]
    private ?string $storageKey = null;

    /** The uploaded name, kept for the download filename - `name` may since have been renamed. */
    #[ORM\Column(name: 'original_name', length: 255, nullable: true)]
    private ?string $originalName = null;

    #[ORM\Column(name: 'mime_type', length: 190, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(name: 'size_bytes', type: Types::BIGINT, nullable: true)]
    private ?int $sizeBytes = null;

    /** sha256, for the duplicate warning. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $checksum = null;

    /** Audio and video, read in the browser at upload time - the server has no ffprobe. */
    #[ORM\Column(name: 'duration_seconds', nullable: true)]
    private ?int $durationSeconds = null;

    #[ORM\Column(name: 'poster_storage_key', length: 255, nullable: true)]
    private ?string $posterStorageKey = null;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    public function __construct(User $owner, FileLibraryNodeType $type, string $name)
    {
        $this->owner = $owner;
        $this->type = $type;
        $this->name = $name;
        $this->children = new ArrayCollection();
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    /** The date a listing sorts and shows: when the file last changed, falling back to its arrival. */
    public function getModifiedAt(): \DateTimeImmutable
    {
        return $this->lastUpdatedDate ?? $this->creationDate;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): self
    {
        $this->parent = $parent;

        return $this;
    }

    /** @return Collection<int, self> */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function getType(): FileLibraryNodeType
    {
        return $this->type;
    }

    public function isFolder(): bool
    {
        return $this->type->isFolder();
    }

    public function isFile(): bool
    {
        return !$this->type->isFolder();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): self
    {
        $this->path = $path;

        return $this;
    }

    public function getDepth(): int
    {
        return $this->depth;
    }

    public function setDepth(int $depth): self
    {
        $this->depth = $depth;

        return $this;
    }

    public function getStorageKey(): ?string
    {
        return $this->storageKey;
    }

    public function setStorageKey(?string $storageKey): self
    {
        $this->storageKey = $storageKey;

        return $this;
    }

    public function getOriginalName(): ?string
    {
        return $this->originalName;
    }

    public function setOriginalName(?string $originalName): self
    {
        $this->originalName = $originalName;

        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): self
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getSizeBytes(): ?int
    {
        return $this->sizeBytes;
    }

    public function setSizeBytes(?int $sizeBytes): self
    {
        $this->sizeBytes = $sizeBytes;

        return $this;
    }

    public function getChecksum(): ?string
    {
        return $this->checksum;
    }

    public function setChecksum(?string $checksum): self
    {
        $this->checksum = $checksum;

        return $this;
    }

    public function getDurationSeconds(): ?int
    {
        return $this->durationSeconds;
    }

    public function setDurationSeconds(?int $durationSeconds): self
    {
        $this->durationSeconds = $durationSeconds;

        return $this;
    }

    public function getPosterStorageKey(): ?string
    {
        return $this->posterStorageKey;
    }

    public function setPosterStorageKey(?string $posterStorageKey): self
    {
        $this->posterStorageKey = $posterStorageKey;

        return $this;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?\DateTimeImmutable $deletedAt): self
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }

    /** The extension of the display name, lowercased - what the row's coloured tile shows. */
    public function getExtension(): string
    {
        return mb_strtolower(pathinfo($this->name, \PATHINFO_EXTENSION));
    }
}
