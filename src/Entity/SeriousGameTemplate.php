<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SeriousGameTemplateStatus;
use App\Repository\SeriousGameTemplateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One version of the template that generates a unit's questions (design/validated/serious-games.md
 * §4, §5).
 *
 * `$definition` is the declarative JSON document read by App\SeriousGame\Template\TemplateInterpreter
 * - never PHP, which is what lets an administrator change it through the connector. Versions are
 * numbered per unit and **never rewritten once validated**: a question is replayed from its seed
 * *and* the version that served it, so an old version must stay exactly as it was.
 *
 * `$author` null means « livré avec le jeu »: written by `app:serious-games:sync` from the
 * repository, validated by the design itself.
 */
#[ORM\Entity(repositoryClass: SeriousGameTemplateRepository::class)]
#[ORM\Table(name: 'serious_game_template')]
#[ORM\UniqueConstraint(name: 'uniq_serious_game_template_version', columns: ['game_id', 'unit_code', 'version'])]
class SeriousGameTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SeriousGame::class)]
    #[ORM\JoinColumn(name: 'game_id', nullable: false, onDelete: 'CASCADE')]
    private ?SeriousGame $game = null;

    #[ORM\Column(name: 'unit_code', length: 40)]
    private string $unitCode;

    #[ORM\Column(name: 'version')]
    private int $version;

    #[ORM\Column(name: 'status', length: 20, enumType: SeriousGameTemplateStatus::class)]
    private SeriousGameTemplateStatus $status = SeriousGameTemplateStatus::Draft;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'definition', type: Types::JSON)]
    private array $definition;

    #[ORM\Column(name: 'note', type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'bench_report', type: Types::JSON, nullable: true)]
    private ?array $benchReport = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'validated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validatedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'validated_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $validatedBy = null;

    /**
     * @param array<string, mixed> $definition
     */
    public function __construct(SeriousGame $game, string $unitCode, int $version, array $definition, ?User $author)
    {
        $this->game = $game;
        $this->unitCode = $unitCode;
        $this->version = $version;
        $this->definition = $definition;
        $this->author = $author;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGame(): SeriousGame
    {
        \assert(null !== $this->game);

        return $this->game;
    }

    public function getUnitCode(): string
    {
        return $this->unitCode;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getStatus(): SeriousGameTemplateStatus
    {
        return $this->status;
    }

    public function isValidated(): bool
    {
        return SeriousGameTemplateStatus::Validated === $this->status;
    }

    public function isDraft(): bool
    {
        return SeriousGameTemplateStatus::Draft === $this->status;
    }

    /** @return array<string, mixed> */
    public function getDefinition(): array
    {
        return $this->definition;
    }

    /**
     * Only a draft is ever rewritten: a validated version may have served questions that are
     * replayed from it.
     *
     * @param array<string, mixed> $definition
     */
    public function replaceDraftDefinition(array $definition): static
    {
        if (!$this->isDraft()) {
            throw new \LogicException('Only a draft template is rewritten.');
        }

        $this->definition = $definition;
        $this->benchReport = null;

        return $this;
    }

    /**
     * `app:serious-games:sync --refresh`: a version shipped with the game, still untouched, takes
     * the repository's text again.
     *
     * @param array<string, mixed> $definition
     */
    public function refreshShippedDefinition(array $definition): static
    {
        if (null !== $this->author) {
            throw new \LogicException('Only a template shipped with the game is refreshed.');
        }

        $this->definition = $definition;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function isShippedWithTheGame(): bool
    {
        return null === $this->author;
    }

    /** @return array<string, mixed>|null */
    public function getBenchReport(): ?array
    {
        return $this->benchReport;
    }

    /** @param array<string, mixed> $benchReport */
    public function setBenchReport(array $benchReport): static
    {
        $this->benchReport = $benchReport;

        return $this;
    }

    public function isBenchGreen(): bool
    {
        return true === ($this->benchReport['green'] ?? false);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getValidatedAt(): ?\DateTimeImmutable
    {
        return $this->validatedAt;
    }

    public function getValidatedBy(): ?User
    {
        return $this->validatedBy;
    }

    public function markValidated(?User $by): static
    {
        $this->status = SeriousGameTemplateStatus::Validated;
        $this->validatedAt = new \DateTimeImmutable();
        $this->validatedBy = $by;

        return $this;
    }

    public function markSuperseded(): static
    {
        $this->status = SeriousGameTemplateStatus::Superseded;

        return $this;
    }
}
