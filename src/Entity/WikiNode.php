<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\WikiNodeType;
use App\Repository\WikiNodeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One node of a wiki's tree - a folder or a page, in a single table (see App\Enum\WikiNodeType for
 * why one table rather than two entities).
 *
 * Three columns deserve a word:
 *
 * - **`path`** holds the ancestors' ids, '/12/48/'. VARCHAR(768) is the largest InnoDB can index
 *   under utf8mb4, which leaves room for roughly eighty levels: "unlimited depth" is satisfied in
 *   practice while the column stays indexable. It is not the sort key - App\Service\WikiTree says
 *   why.
 * - **`slug`** is unique among siblings, and that uniqueness is enforced by
 *   App\Service\WikiTree::uniqueSlug(), never by the index: MySQL treats every NULL as distinct, so
 *   UNIQUE (wiki_id, parent_id, slug) would enforce nothing at all at root level.
 * - **`bodyText`** is the de-tagged copy of `body`, rebuilt on every save and FULLTEXT-indexed. A
 *   separate column rather than a LIKE over `body` so that searching for "table" does not match the
 *   HTML tag, and so the index is usable at all.
 *
 * The soft edit lock ($lockedBy/$lockedAt) prevents nothing: it removes the silent overwrite by
 * telling the second person that somebody is already in there. Stale after 5 minutes, refreshed by
 * a heartbeat every 60 seconds.
 */
#[ORM\Entity(repositoryClass: WikiNodeRepository::class)]
#[ORM\Table(name: 'wiki_node')]
#[ORM\Index(name: 'idx_wiki_node_wiki', columns: ['wiki_id', 'deleted_at'])]
#[ORM\Index(name: 'idx_wiki_node_path', columns: ['path'])]
// Deliberately NOT unique - see the class docblock. This is a lookup index for "the slugs already
// taken among these siblings", which is the question uniqueSlug() asks.
#[ORM\Index(name: 'idx_wiki_node_slug', columns: ['wiki_id', 'parent_id', 'slug'])]
// The rail's search. FULLTEXT over the de-tagged copy and the title together, so one MATCH answers
// "this word is in the page" whether it is in the heading or in the body - a second index on
// `title` alone would need a second MATCH and a UNION to combine them.
#[ORM\Index(name: 'ft_wiki_node_search', columns: ['title', 'body_text'], flags: ['fulltext'])]
class WikiNode
{
    /** How long a soft edit lock is believed before it reads as abandoned. */
    public const int LOCK_STALE_AFTER_SECONDS = 300;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Wiki::class, inversedBy: 'nodes')]
    #[ORM\JoinColumn(name: 'wiki_id', nullable: false, onDelete: 'CASCADE')]
    private ?Wiki $wiki = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(name: 'parent_id', nullable: true, onDelete: 'CASCADE')]
    private ?self $parent = null;

    /** @var Collection<int, self> */
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)]
    private Collection $children;

    #[ORM\Column(length: 20, enumType: WikiNodeType::class)]
    private WikiNodeType $type;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 255)]
    private string $slug;

    #[ORM\Column]
    private int $position = 1;

    #[ORM\Column(length: 768)]
    private string $path = '/';

    #[ORM\Column(type: Types::SMALLINT)]
    private int $depth = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $body = null;

    #[ORM\Column(name: 'body_text', type: Types::TEXT, nullable: true)]
    private ?string $bodyText = null;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'locked_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $lockedBy = null;

    #[ORM\Column(name: 'locked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lockedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, WikiAttachment> */
    #[ORM\OneToMany(mappedBy: 'node', targetEntity: WikiAttachment::class, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $attachments;

    public function __construct(Wiki $wiki, WikiNodeType $type, string $title, string $slug, ?User $createdBy = null)
    {
        $this->wiki = $wiki;
        $this->type = $type;
        $this->title = $title;
        $this->slug = $slug;
        $this->createdBy = $createdBy;
        $this->updatedBy = $createdBy;
        $this->children = new ArrayCollection();
        $this->attachments = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWiki(): ?Wiki
    {
        return $this->wiki;
    }

    public function setWiki(?Wiki $wiki): static
    {
        $this->wiki = $wiki;

        return $this;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    /** @return Collection<int, self> */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function getType(): WikiNodeType
    {
        return $this->type;
    }

    public function setType(WikiNodeType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function isFolder(): bool
    {
        return WikiNodeType::Folder === $this->type;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): static
    {
        $this->path = $path;

        return $this;
    }

    public function getDepth(): int
    {
        return $this->depth;
    }

    public function setDepth(int $depth): static
    {
        $this->depth = $depth;

        return $this;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function getBodyText(): ?string
    {
        return $this->bodyText;
    }

    public function setBodyText(?string $bodyText): static
    {
        $this->bodyText = $bodyText;

        return $this;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?\DateTimeImmutable $deletedAt): static
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }

    public function getLockedBy(): ?User
    {
        return $this->lockedBy;
    }

    public function getLockedAt(): ?\DateTimeImmutable
    {
        return $this->lockedAt;
    }

    public function lockFor(User $user, ?\DateTimeImmutable $at = null): static
    {
        $this->lockedBy = $user;
        $this->lockedAt = $at ?? new \DateTimeImmutable();

        return $this;
    }

    public function releaseLock(): static
    {
        $this->lockedBy = null;
        $this->lockedAt = null;

        return $this;
    }

    /** Is somebody else believed to be editing this page right now? */
    public function isLockedFor(User $viewer, ?\DateTimeImmutable $now = null): bool
    {
        if (null === $this->lockedBy || null === $this->lockedAt || $this->lockedBy === $viewer) {
            return false;
        }

        $now ??= new \DateTimeImmutable();

        return $now->getTimestamp() - $this->lockedAt->getTimestamp() < self::LOCK_STALE_AFTER_SECONDS;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(?User $by = null): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        if (null !== $by) {
            $this->updatedBy = $by;
        }

        return $this;
    }

    /** @return Collection<int, WikiAttachment> */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function addAttachment(WikiAttachment $attachment): static
    {
        if (!$this->attachments->contains($attachment)) {
            $this->attachments->add($attachment);
            $attachment->setNode($this);
        }

        return $this;
    }

    public function removeAttachment(WikiAttachment $attachment): static
    {
        if ($this->attachments->removeElement($attachment)) {
            $attachment->setNode(null);
        }

        return $this;
    }
}
