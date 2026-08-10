<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\HelpArticleKind;
use App\Enum\HelpAudience;
use App\Repository\HelpArticleRepository;
use App\Service\HelpLocaleResolver;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One entry of the help: a step-by-step article, a frequently-asked question, or a glossary term
 * (see App\Enum\HelpArticleKind). The three share a table because the search screen searches them
 * as one index and because they carry exactly the same fields.
 *
 * $summary is what the reader sees before opening anything: the chapeau of an article, the whole
 * answer of a FAQ entry, the definition of a glossary term. $body is the article page itself and
 * stays empty for the other two kinds.
 *
 * $audiences narrows the section's own audiences and is never widened by App\Service\HelpAccess:
 * an article addressed to teachers inside a section addressed to staff reaches nobody but an admin,
 * which is a content mistake the admin screens surface rather than silently repair.
 */
#[ORM\Entity(repositoryClass: HelpArticleRepository::class)]
#[ORM\Table(name: 'help_article')]
#[ORM\UniqueConstraint(name: 'uniq_help_article_slug', columns: ['section_id', 'slug', 'locale'])]
#[ORM\Index(name: 'idx_help_article_kind', columns: ['kind', 'published'])]
class HelpArticle
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Nullable in PHP only: the column is NOT NULL, but a new article exists in the admin form
    // before its section has been picked.
    #[ORM\ManyToOne(targetEntity: HelpSection::class, inversedBy: 'articles')]
    #[ORM\JoinColumn(name: 'section_id', nullable: false)]
    #[Assert\NotNull]
    private ?HelpSection $section = null;

    #[ORM\Column(length: 20, enumType: HelpArticleKind::class)]
    private HelpArticleKind $kind = HelpArticleKind::Article;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'helpSlugFormatViolation')]
    private string $slug;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    private string $title;

    // Same as HelpSection::$locale: the language this entry is written in. A translation is a
    // second row with the same slug, resolved at read time by App\Service\HelpLocaleResolver.
    #[ORM\Column(length: 5)]
    private string $locale = HelpLocaleResolver::DEFAULT_LOCALE;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $summary = '';

    // HugeRTE-authored HTML, sanitized on the way in (app.help_article_body) and rendered raw.
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $body = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $audiences = [];

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $published = true;

    // Feeds "Les plus consultés" on the help home. Counted once per article view, without any
    // per-reader deduplication: it ranks pages, it does not track people.
    #[ORM\Column(name: 'view_count')]
    private int $viewCount = 0;

    #[ORM\Column(name: 'helpful_yes_count')]
    private int $helpfulYesCount = 0;

    #[ORM\Column(name: 'helpful_no_count')]
    private int $helpfulNoCount = 0;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    public function __construct(?HelpSection $section, string $slug, string $title)
    {
        $this->section = $section;
        $this->slug = $slug;
        $this->title = $title;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSection(): ?HelpSection
    {
        return $this->section;
    }

    public function setSection(?HelpSection $section): static
    {
        $this->section = $section;

        return $this;
    }

    public function getKind(): HelpArticleKind
    {
        return $this->kind;
    }

    public function setKind(HelpArticleKind $kind): static
    {
        $this->kind = $kind;

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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function setSummary(string $summary): static
    {
        $this->summary = $summary;

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

    /** @return list<HelpAudience> */
    public function getAudiences(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $value): ?HelpAudience => HelpAudience::tryFrom($value),
            $this->audiences,
        )));
    }

    /** @param array<array-key, HelpAudience> $audiences */
    public function setAudiences(array $audiences): static
    {
        $this->audiences = array_values(array_unique(array_map(
            static fn (HelpAudience $audience): string => $audience->value,
            $audiences,
        )));

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

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function setPublished(bool $published): static
    {
        $this->published = $published;

        return $this;
    }

    public function getViewCount(): int
    {
        return $this->viewCount;
    }

    public function incrementViewCount(): static
    {
        ++$this->viewCount;

        return $this;
    }

    public function getHelpfulYesCount(): int
    {
        return $this->helpfulYesCount;
    }

    public function getHelpfulNoCount(): int
    {
        return $this->helpfulNoCount;
    }

    public function recordHelpfulVote(bool $helpful): static
    {
        if ($helpful) {
            ++$this->helpfulYesCount;
        } else {
            ++$this->helpfulNoCount;
        }

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?User $updatedBy): static
    {
        $this->updatedBy = $updatedBy;

        return $this;
    }
}
