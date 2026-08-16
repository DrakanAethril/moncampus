<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WikiRevisionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One saved state of a page: title and body as they were, and who wrote them.
 *
 * Capped at the 50 most recent per node, pruned on write - without the cap, a wiki used seriously
 * for a year is the biggest table in the schema. This history is also why the feature logs nothing
 * into App\Entity\PlatformActivity: it already records who changed what and when, page by page, and
 * duplicating it would only make two sources of truth that can disagree.
 */
#[ORM\Entity(repositoryClass: WikiRevisionRepository::class)]
#[ORM\Table(name: 'wiki_revision')]
#[ORM\Index(name: 'idx_wiki_revision_node', columns: ['node_id', 'created_at'])]
class WikiRevision
{
    /** How many states of one page are kept. */
    public const int KEEP_PER_NODE = 50;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WikiNode::class)]
    #[ORM\JoinColumn(name: 'node_id', nullable: false, onDelete: 'CASCADE')]
    private ?WikiNode $node = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $body = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(WikiNode $node, string $title, ?string $body, ?User $author)
    {
        $this->node = $node;
        $this->title = $title;
        $this->body = $body;
        $this->author = $author;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNode(): ?WikiNode
    {
        return $this->node;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
