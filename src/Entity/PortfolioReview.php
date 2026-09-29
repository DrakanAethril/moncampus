<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PortfolioReviewDecision;
use App\Repository\PortfolioReviewRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One decision of a validateur on one revision of a réalisation or of an E6 fiche.
 *
 * **Append-only**, like App\Entity\DossierReview and the game's ledger: a validateur who changes
 * their mind writes a second decision. That is what lets the journal say what happened rather than
 * what somebody currently thinks, and why a decision holds even after its author stops being a
 * validateur (R2b).
 *
 * `snapshot` is the piece as it was read when decided - what a later revision is compared against
 * (« ce qui a changé depuis la version validée »); `claims` the per-competency decisions, by
 * competency id.
 */
#[ORM\Entity(repositoryClass: PortfolioReviewRepository::class)]
#[ORM\Table(name: 'portfolio_review')]
class PortfolioReview
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PortfolioAchievement::class, inversedBy: 'reviews')]
    #[ORM\JoinColumn(name: 'achievement_id', nullable: true, onDelete: 'CASCADE')]
    private ?PortfolioAchievement $achievement = null;

    #[ORM\ManyToOne(targetEntity: PortfolioShowcase::class, inversedBy: 'reviews')]
    #[ORM\JoinColumn(name: 'showcase_id', nullable: true, onDelete: 'CASCADE')]
    private ?PortfolioShowcase $showcase = null;

    #[ORM\Column]
    private int $revision;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'reviewer_id', nullable: false)]
    private ?User $reviewer = null;

    #[ORM\Column(name: 'decided_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $decidedAt;

    #[ORM\Column(length: 20, enumType: PortfolioReviewDecision::class)]
    private PortfolioReviewDecision $decision;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    /** @var array<array-key, array{retained: bool, comment: ?string}> keyed by competency id */
    #[ORM\Column(type: Types::JSON)]
    private array $claims = [];

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $snapshot = [];

    /** « Environnement technologique conforme à l'annexe II.E » - asked on an E6 fiche only. */
    #[ORM\Column(name: 'environment_compliant', nullable: true)]
    private ?bool $environmentCompliant = null;

    /**
     * @param array<array-key, array{retained: bool, comment: ?string}> $claims
     * @param array<string, mixed>                                   $snapshot
     *
     * @throws \InvalidArgumentException when a piece is sent back without saying why (R6)
     */
    public function __construct(PortfolioAchievement|PortfolioShowcase $subject, User $reviewer, PortfolioReviewDecision $decision, ?string $comment, array $claims, array $snapshot, ?bool $environmentCompliant = null)
    {
        $comment = null === $comment ? null : trim($comment);

        if ($decision->requiresComment() && (null === $comment || '' === $comment)) {
            throw new \InvalidArgumentException('Sending a piece back must say why.');
        }

        if ($subject instanceof PortfolioAchievement) {
            $this->achievement = $subject;
        } else {
            $this->showcase = $subject;
        }

        $this->revision = $subject->getRevision();
        $this->reviewer = $reviewer;
        $this->decision = $decision;
        $this->comment = '' === $comment ? null : $comment;
        $this->claims = $claims;
        $this->snapshot = $snapshot;
        $this->environmentCompliant = $environmentCompliant;
        $this->decidedAt = new \DateTimeImmutable();
        $subject->addReview($this);
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

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getReviewer(): ?User
    {
        return $this->reviewer;
    }

    public function getDecidedAt(): \DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function getDecision(): PortfolioReviewDecision
    {
        return $this->decision;
    }

    public function isValidation(): bool
    {
        return PortfolioReviewDecision::Validated === $this->decision;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    /** @return array<array-key, array{retained: bool, comment: ?string}> */
    public function getClaims(): array
    {
        return $this->claims;
    }

    /** @return array<string, mixed> */
    public function getSnapshot(): array
    {
        return $this->snapshot;
    }

    public function getEnvironmentCompliant(): ?bool
    {
        return $this->environmentCompliant;
    }
}
