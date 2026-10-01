<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CompanyCategoryFlag;
use App\Enum\CompanyCategoryTheme;
use App\Enum\EmployeeBand;
use App\Repository\CompanySearchCategoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * One line of « Quoi ? » on « Trouver une entreprise »: a name a student understands (« Services
 * numériques (ESN) ») standing for what the register understands (NAF codes, or a flag).
 *
 * **One list for the whole establishment** (design/validated/vivier-entreprises.md, D5): no
 * category belongs to a filière, every student filters in the same list. Kept by administrators.
 *
 * A category is made of NAF codes, **or** of one API flag (a collectivity), **or** of a minimum
 * size alone (« Grandes entreprises, tous secteurs »). The last two cannot be merged with code
 * categories into a single request - the API ANDs its filters - so they are searched on their
 * own: isSolo().
 */
#[ORM\Entity(repositoryClass: CompanySearchCategoryRepository::class)]
#[ORM\Table(name: 'company_search_category')]
class CompanySearchCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: CompanyCategoryTheme::class)]
    private CompanyCategoryTheme $theme = CompanyCategoryTheme::Digital;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $label = '';

    /** One line for the student, shown under the box (« C'est là que se font la plupart des stages SLAM »). */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $hint = null;

    /**
     * NAF rév. 2 codes, `62.02A`. Stored as data, never written in code: the INSEE moves to NAF 2025,
     * and on that day the translation is a data migration, not a release.
     *
     * @var list<string>
     */
    #[ORM\Column(name: 'naf_codes', type: Types::JSON)]
    private array $nafCodes = [];

    #[ORM\Column(length: 30, nullable: true, enumType: CompanyCategoryFlag::class)]
    private ?CompanyCategoryFlag $flag = null;

    /** « 250 salariés et plus » for the all-sector category; null everywhere else. */
    #[ORM\Column(name: 'minimum_band', length: 10, nullable: true, enumType: EmployeeBand::class)]
    private ?EmployeeBand $minimumBand = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[Assert\Callback]
    public function validateDefinition(ExecutionContextInterface $context): void
    {
        $ways = ([] !== $this->nafCodes ? 1 : 0) + (null !== $this->flag ? 1 : 0);

        if (0 === $ways && null === $this->minimumBand) {
            $context->buildViolation('companyCategoryEmptyDefinitionError')->atPath('nafCodes')->addViolation();
        }

        if ($ways > 1) {
            $context->buildViolation('companyCategoryCodesOrFlagError')->atPath('flag')->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTheme(): CompanyCategoryTheme
    {
        return $this->theme;
    }

    public function setTheme(CompanyCategoryTheme $theme): static
    {
        $this->theme = $theme;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = trim((string) $label);

        return $this;
    }

    public function getHint(): ?string
    {
        return $this->hint;
    }

    public function setHint(?string $hint): static
    {
        $hint = null !== $hint ? trim($hint) : null;
        $this->hint = '' !== $hint ? $hint : null;

        return $this;
    }

    /** @return list<string> */
    public function getNafCodes(): array
    {
        return $this->nafCodes;
    }

    /** @param array<array-key, string> $nafCodes normalised: upper case, deduplicated, sorted */
    public function setNafCodes(array $nafCodes): static
    {
        $codes = array_map(static fn (string $code): string => mb_strtoupper(trim($code)), $nafCodes);
        $codes = array_values(array_unique(array_filter($codes, static fn (string $code): bool => '' !== $code)));
        sort($codes);
        $this->nafCodes = $codes;

        return $this;
    }

    public function getFlag(): ?CompanyCategoryFlag
    {
        return $this->flag;
    }

    public function setFlag(?CompanyCategoryFlag $flag): static
    {
        $this->flag = $flag;

        return $this;
    }

    public function getMinimumBand(): ?EmployeeBand
    {
        return $this->minimumBand;
    }

    public function setMinimumBand(?EmployeeBand $minimumBand): static
    {
        $this->minimumBand = $minimumBand;

        return $this;
    }

    /**
     * Searched on its own: a flag or a size alone cannot be ORed with codes in one request, and two
     * requests would mean two paginations under one list.
     */
    public function isSolo(): bool
    {
        return [] === $this->nafCodes;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
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

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function touch(User $by): static
    {
        $this->updatedAt = new \DateTimeImmutable();
        $this->updatedBy = $by;

        return $this;
    }
}
