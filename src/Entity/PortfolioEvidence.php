<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PortfolioEvidenceKind;
use App\Repository\PortfolioEvidenceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One piece of evidence - a document, a link, a piece of work handed in.
 *
 * Hangs off **exactly one** parent: a réalisation (its « productions et documents associés »), an
 * E6 fiche (its « éléments constitutifs ») or the portfolio itself (the internship attestations of
 * the E5 dossier). The constructor refuses anything else.
 *
 * A file's bytes are never deleted when the evidence is removed: a deposit already made may cite
 * them, and a deposit is frozen (R9). They go with the portfolio's retention, not before.
 */
#[ORM\Entity(repositoryClass: PortfolioEvidenceRepository::class)]
#[ORM\Table(name: 'portfolio_evidence')]
class PortfolioEvidence
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PortfolioAchievement::class, inversedBy: 'evidences')]
    #[ORM\JoinColumn(name: 'achievement_id', nullable: true, onDelete: 'CASCADE')]
    private ?PortfolioAchievement $achievement = null;

    #[ORM\ManyToOne(targetEntity: PortfolioShowcase::class, inversedBy: 'evidences')]
    #[ORM\JoinColumn(name: 'showcase_id', nullable: true, onDelete: 'CASCADE')]
    private ?PortfolioShowcase $showcase = null;

    #[ORM\ManyToOne(targetEntity: Portfolio::class, inversedBy: 'attestations')]
    #[ORM\JoinColumn(name: 'portfolio_id', nullable: true, onDelete: 'CASCADE')]
    private ?Portfolio $portfolio = null;

    #[ORM\Column(length: 20, enumType: PortfolioEvidenceKind::class)]
    private PortfolioEvidenceKind $kind;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(name: 'file_key', length: 255, nullable: true)]
    private ?string $fileKey = null;

    #[ORM\Column(name: 'original_name', length: 255, nullable: true)]
    private ?string $originalName = null;

    #[ORM\Column(name: 'mime_type', length: 150, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(name: 'size_bytes', nullable: true)]
    private ?int $sizeBytes = null;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $url = null;

    #[ORM\ManyToOne(targetEntity: AssignmentSubmission::class)]
    #[ORM\JoinColumn(name: 'submission_id', nullable: true, onDelete: 'SET NULL')]
    private ?AssignmentSubmission $submission = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    private function __construct(PortfolioEvidenceKind $kind, string $label, PortfolioAchievement|PortfolioShowcase|Portfolio $parent)
    {
        $this->kind = $kind;
        $this->label = mb_substr(trim($label), 0, 255);
        $this->createdAt = new \DateTimeImmutable();

        match (true) {
            $parent instanceof PortfolioAchievement => $this->attachTo($parent),
            $parent instanceof PortfolioShowcase => $this->attachToShowcase($parent),
            default => $this->attachToPortfolio($parent),
        };
    }

    public static function file(PortfolioAchievement|PortfolioShowcase|Portfolio $parent, string $label, string $fileKey, string $originalName, ?string $mimeType, ?int $sizeBytes): self
    {
        $evidence = new self(PortfolioEvidenceKind::File, '' === trim($label) ? $originalName : $label, $parent);
        $evidence->fileKey = $fileKey;
        $evidence->originalName = $originalName;
        $evidence->mimeType = $mimeType;
        $evidence->sizeBytes = $sizeBytes;

        return $evidence;
    }

    /**
     * @throws \InvalidArgumentException on anything but an http(s) address
     */
    public static function link(PortfolioAchievement|PortfolioShowcase|Portfolio $parent, string $label, string $url): self
    {
        $url = trim($url);
        $scheme = strtolower((string) parse_url($url, \PHP_URL_SCHEME));

        if (!\in_array($scheme, ['http', 'https'], true) || false === filter_var($url, \FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('A link must be an http(s) address.');
        }

        $evidence = new self(PortfolioEvidenceKind::Link, '' === trim($label) ? $url : $label, $parent);
        $evidence->url = $url;

        return $evidence;
    }

    public static function submission(PortfolioAchievement|PortfolioShowcase $parent, string $label, AssignmentSubmission $submission): self
    {
        $evidence = new self(PortfolioEvidenceKind::Submission, $label, $parent);
        $evidence->submission = $submission;

        return $evidence;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAchievement(): ?PortfolioAchievement
    {
        return $this->achievement;
    }

    public function getShowcase(): ?PortfolioShowcase
    {
        return $this->showcase;
    }

    public function getPortfolio(): ?Portfolio
    {
        return $this->portfolio;
    }

    /** The portfolio this evidence belongs to, whichever parent it hangs off. */
    public function getOwningPortfolio(): ?Portfolio
    {
        return $this->portfolio ?? $this->achievement?->getPortfolio() ?? $this->showcase?->getPortfolio();
    }

    public function getKind(): PortfolioEvidenceKind
    {
        return $this->kind;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getFileKey(): ?string
    {
        return $this->fileKey;
    }

    public function getOriginalName(): ?string
    {
        return $this->originalName;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function isImage(): bool
    {
        return null !== $this->mimeType && str_starts_with($this->mimeType, 'image/');
    }

    public function getSizeBytes(): ?int
    {
        return $this->sizeBytes;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getSubmission(): ?AssignmentSubmission
    {
        return $this->submission;
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

    private function attachTo(PortfolioAchievement $achievement): void
    {
        $this->achievement = $achievement;
        $achievement->addEvidence($this);
    }

    private function attachToShowcase(PortfolioShowcase $showcase): void
    {
        $this->showcase = $showcase;
        $showcase->addEvidence($this);
    }

    private function attachToPortfolio(Portfolio $portfolio): void
    {
        $this->portfolio = $portfolio;
        $portfolio->addAttestation($this);
    }
}
