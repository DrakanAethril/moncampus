<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\HelpAudience;
use App\Repository\HelpSectionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One card of the help home (design_handoff_aide, écran 1) and the level above an article.
 *
 * A section carries its own audiences so a whole area can be addressed to teachers or to staff in
 * one move; an article may then narrow further, never widen - see App\Service\HelpAccess, which is
 * the single place that answers "may this person read this".
 *
 * The slug is what the URL carries (/help/{section}), so it is stable: renaming a section changes
 * its title, not its address, unless the admin edits the slug on purpose.
 */
#[ORM\Entity(repositoryClass: HelpSectionRepository::class)]
#[ORM\Table(name: 'help_section')]
#[ORM\UniqueConstraint(name: 'uniq_help_section_slug', columns: ['slug'])]
class HelpSection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'helpSlugFormatViolation')]
    private string $slug;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $title;

    // The single line under the card title. Plain text on purpose: the card has room for one line
    // and the handoff shows no rich text there.
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $description = '';

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $audiences = [];

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $published = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    /** @var Collection<int, HelpArticle> */
    #[ORM\OneToMany(targetEntity: HelpArticle::class, mappedBy: 'section')]
    #[ORM\OrderBy(['position' => 'ASC', 'title' => 'ASC'])]
    private Collection $articles;

    public function __construct(string $slug, string $title)
    {
        $this->slug = $slug;
        $this->title = $title;
        $this->createdAt = new \DateTimeImmutable();
        $this->articles = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

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

    /** @return Collection<int, HelpArticle> */
    public function getArticles(): Collection
    {
        return $this->articles;
    }
}
