<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DocumentationAudience;
use App\Enum\DocumentationStatus;
use App\Repository\DocumentationArticleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * One entry of the "Base documentaire" (design/design_handoff_base_documentaire) - a documentary
 * article such as "Liste des certifications gratuites 2026", written by a teacher or a member of
 * the personnel and read by the campus.
 *
 * Who reads it is the AND of two independent things, and neither is a substitute for the other:
 *
 * - $audiences says *what kind* of person (students, teachers, personnel, tutors);
 * - $perimeter says *which* of them, as groups of the App\Entity\Group hierarchy - "Campus" for
 *   everyone, "BTS SIO" for a filière, "SIO 2 — A" for one class. A reader matches when one of
 *   their own roles is one of these groups' roles, or one below it.
 *
 * App\Service\DocumentationAccess is the single place that answers the question; nothing else
 * should re-implement either half.
 *
 * The two read counters are deliberately separate columns rather than a query over a log table:
 * $readCount is the historical total and is never reset, $readCountSinceReset is zeroed campus-wide
 * from the dashboard (App\Entity\DocumentationCounterReset keeps the date). A single article page
 * therefore costs one UPDATE, whatever the traffic.
 */
#[ORM\Entity(repositoryClass: DocumentationArticleRepository::class)]
#[ORM\Table(name: 'documentation_article')]
#[ORM\Index(name: 'idx_documentation_article_status', columns: ['status', 'published_at'])]
class DocumentationArticle
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $title = '';

    // "Accroche" - the two lines under the title on a card (2a/2b) and above the body (2c/2e).
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $excerpt = '';

    // HugeRTE-authored HTML, sanitized on the way in (app.documentation_article_body) and
    // rendered raw. Empty on a freshly created draft, which is why it is nullable.
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $body = null;

    #[ORM\Column(length: 20, enumType: DocumentationStatus::class)]
    private DocumentationStatus $status = DocumentationStatus::Draft;

    // Null/null is the "Permanente" radio of 2d; either bound set is "Sur une période". Both are
    // read by App\Service\DocumentationAccess, never by a screen on its own.
    #[ORM\Column(name: 'publish_start', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishStart = null;

    #[ORM\Column(name: 'publish_end', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishEnd = null;

    #[ORM\Column]
    private bool $pinned = false;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', nullable: false)]
    private ?User $author = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    // Stamped the first time the article reaches Published and kept afterwards: 2e shows
    // "Dernière mise à jour" only when it differs from this, so a re-publication must not move it.
    #[ORM\Column(name: 'published_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    /**
     * The groups this article is addressed to - a group covers everything below it in the
     * hierarchy, so "BTS SIO" reaches its own classes too.
     *
     * @var Collection<int, Group>
     */
    #[ORM\ManyToMany(targetEntity: Group::class)]
    #[ORM\JoinTable(name: 'documentation_article_group')]
    #[Assert\Count(min: 1, minMessage: 'documentationPerimeterRequiredMessage')]
    private Collection $perimeter;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    #[Assert\Count(min: 1, minMessage: 'documentationVisibilityRequiredMessage')]
    private array $audiences = [];

    /** @var Collection<int, DocumentationTag> */
    #[ORM\ManyToMany(targetEntity: DocumentationTag::class, inversedBy: 'articles')]
    #[ORM\JoinTable(name: 'documentation_article_tag')]
    private Collection $tags;

    /** @var Collection<int, DocumentationArticleAttachment> */
    #[ORM\OneToMany(mappedBy: 'article', targetEntity: DocumentationArticleAttachment::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $attachments;

    #[ORM\Column(name: 'read_count')]
    private int $readCount = 0;

    #[ORM\Column(name: 'read_count_since_reset')]
    private int $readCountSinceReset = 0;

    public function __construct(User $author)
    {
        $this->author = $author;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->perimeter = new ArrayCollection();
        $this->tags = new ArrayCollection();
        $this->attachments = new ArrayCollection();
        $this->audiences = array_map(
            static fn (DocumentationAudience $audience): string => $audience->value,
            DocumentationAudience::defaults()
        );
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getExcerpt(): string
    {
        return $this->excerpt;
    }

    public function setExcerpt(string $excerpt): static
    {
        $this->excerpt = $excerpt;

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

    public function getStatus(): DocumentationStatus
    {
        return $this->status;
    }

    public function setStatus(DocumentationStatus $status): static
    {
        $this->status = $status;

        if (DocumentationStatus::Published === $status && null === $this->publishedAt) {
            $this->publishedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getPublishStart(): ?\DateTimeImmutable
    {
        return $this->publishStart;
    }

    public function setPublishStart(?\DateTimeImmutable $publishStart): static
    {
        $this->publishStart = $publishStart;

        return $this;
    }

    public function getPublishEnd(): ?\DateTimeImmutable
    {
        return $this->publishEnd;
    }

    public function setPublishEnd(?\DateTimeImmutable $publishEnd): static
    {
        $this->publishEnd = $publishEnd;

        return $this;
    }

    public function isTimeBound(): bool
    {
        return null !== $this->publishStart || null !== $this->publishEnd;
    }

    public function isPinned(): bool
    {
        return $this->pinned;
    }

    public function setPinned(bool $pinned): static
    {
        $this->pinned = $pinned;

        return $this;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(User $author): static
    {
        $this->author = $author;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): static
    {
        $this->publishedAt = $publishedAt;

        return $this;
    }

    /** @return Collection<int, Group> */
    public function getPerimeter(): Collection
    {
        return $this->perimeter;
    }

    public function addPerimeterGroup(Group $group): static
    {
        if (!$this->perimeter->contains($group)) {
            $this->perimeter->add($group);
        }

        return $this;
    }

    public function removePerimeterGroup(Group $group): static
    {
        $this->perimeter->removeElement($group);

        return $this;
    }

    /** @return list<int> */
    public function getPerimeterIds(): array
    {
        $ids = [];

        foreach ($this->perimeter as $group) {
            $id = $group->getId();

            if (null !== $id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @return list<DocumentationAudience> */
    public function getAudiences(): array
    {
        $audiences = [];

        foreach ($this->audiences as $value) {
            $audience = DocumentationAudience::tryFrom($value);

            if (null !== $audience) {
                $audiences[] = $audience;
            }
        }

        return $audiences;
    }

    /** @param array<array-key, DocumentationAudience> $audiences */
    public function setAudiences(array $audiences): static
    {
        $values = [];

        foreach ($audiences as $audience) {
            $values[$audience->value] = true;
        }

        $this->audiences = array_keys($values);

        return $this;
    }

    public function hasAudience(DocumentationAudience $audience): bool
    {
        return \in_array($audience->value, $this->audiences, true);
    }

    /** @return Collection<int, DocumentationTag> */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(DocumentationTag $tag): static
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }

        return $this;
    }

    public function removeTag(DocumentationTag $tag): static
    {
        $this->tags->removeElement($tag);

        return $this;
    }

    /** @return Collection<int, DocumentationArticleAttachment> */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function addAttachment(DocumentationArticleAttachment $attachment): static
    {
        if (!$this->attachments->contains($attachment)) {
            $this->attachments->add($attachment);
            $attachment->setArticle($this);
        }

        return $this;
    }

    public function removeAttachment(DocumentationArticleAttachment $attachment): static
    {
        $this->attachments->removeElement($attachment);

        return $this;
    }

    public function getReadCount(): int
    {
        return $this->readCount;
    }

    public function getReadCountSinceReset(): int
    {
        return $this->readCountSinceReset;
    }

    /**
     * One more opening of the article page. Both counters move together; only the second one is
     * ever put back to zero, by App\Service\DocumentationReadCounter::reset().
     */
    public function registerRead(): static
    {
        ++$this->readCount;
        ++$this->readCountSinceReset;

        return $this;
    }

    public function resetReadCountSinceReset(): static
    {
        $this->readCountSinceReset = 0;

        return $this;
    }

    /**
     * A period that ends before it starts would silently hide the article, with the two dates on
     * screen saying it should be visible.
     */
    #[Assert\Callback]
    public function validateDiffusionWindow(ExecutionContextInterface $context): void
    {
        if (null !== $this->publishStart && null !== $this->publishEnd && $this->publishEnd <= $this->publishStart) {
            $context->buildViolation('documentationPeriodOrderMessage')
                ->atPath('publishEnd')
                ->addViolation();
        }
    }
}
