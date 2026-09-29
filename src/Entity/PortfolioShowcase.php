<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PortfolioState;
use App\Repository\PortfolioShowcaseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An E6 fiche - annexe VII-1-A (SISR) or VII-1-B (SLAM), recto and verso.
 *
 * **Born of a validated réalisation, and it follows it** (R11): the recto's identity - organisation,
 * dates, title, the competencies ticked - is read from the réalisation, never copied, so it can only
 * change there; and a réalisation that changes sends its fiche back to « À valider » too. What only
 * the fiche asks for is written here: the conditions, the resources, the access to the productions
 * and the verso.
 *
 * Two at most, on two different réalisations (R12): UNIQUE (portfolio, number) and (portfolio,
 * achievement).
 */
#[ORM\Entity(repositoryClass: PortfolioShowcaseRepository::class)]
#[ORM\Table(name: 'portfolio_showcase')]
#[ORM\UniqueConstraint(name: 'portfolio_showcase_number_unique', columns: ['portfolio_id', 'number'])]
#[ORM\UniqueConstraint(name: 'portfolio_showcase_achievement_unique', columns: ['portfolio_id', 'achievement_id'])]
class PortfolioShowcase
{
    public const array NUMBERS = [1, 2];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Portfolio::class, inversedBy: 'showcases')]
    #[ORM\JoinColumn(name: 'portfolio_id', nullable: false, onDelete: 'CASCADE')]
    private ?Portfolio $portfolio = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $number;

    #[ORM\ManyToOne(targetEntity: PortfolioAchievement::class)]
    #[ORM\JoinColumn(name: 'achievement_id', nullable: false)]
    private ?PortfolioAchievement $achievement = null;

    /** The réalisation's revision this fiche was last written against. */
    #[ORM\Column(name: 'achievement_revision')]
    private int $achievementRevision;

    /** « Conditions de réalisation (ressources fournies, résultats attendus) ». */
    #[ORM\Column(name: 'conditions_html', type: Types::TEXT, nullable: true)]
    private ?string $conditionsHtml = null;

    /** « Description des ressources documentaires, matérielles et logicielles utilisées ». */
    #[ORM\Column(name: 'resources_html', type: Types::TEXT, nullable: true)]
    private ?string $resourcesHtml = null;

    /** « Modalités d'accès aux productions et à leur documentation » - required to submit (R13). */
    #[ORM\Column(name: 'access_html', type: Types::TEXT, nullable: true)]
    private ?string $accessHtml = null;

    /** The verso: « descriptif de la réalisation, y compris productions réalisées et schémas ». */
    #[ORM\Column(name: 'description_html', type: Types::TEXT, nullable: true)]
    private ?string $descriptionHtml = null;

    #[ORM\Column(length: 20, enumType: PortfolioState::class)]
    private PortfolioState $state = PortfolioState::Draft;

    #[ORM\Column(options: ['default' => 0])]
    private int $revision = 0;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'requested_reviewer_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $requestedReviewer = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'submitted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(name: 'validated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validatedAt = null;

    /** @var Collection<int, PortfolioEvidence> the « éléments constitutifs » */
    #[ORM\OneToMany(mappedBy: 'showcase', targetEntity: PortfolioEvidence::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $evidences;

    /** @var Collection<int, PortfolioReview> */
    #[ORM\OneToMany(mappedBy: 'showcase', targetEntity: PortfolioReview::class)]
    #[ORM\OrderBy(['decidedAt' => 'DESC', 'id' => 'DESC'])]
    private Collection $reviews;

    public function __construct(Portfolio $portfolio, int $number, PortfolioAchievement $achievement)
    {
        if (!\in_array($number, self::NUMBERS, true)) {
            throw new \InvalidArgumentException('An E6 dossier holds fiche 1 and fiche 2, nothing else.');
        }

        $this->portfolio = $portfolio;
        $this->number = $number;
        $this->achievement = $achievement;
        $this->achievementRevision = $achievement->getRevision();
        // The verso starts from what the réalisation already says - §6, screen 8.
        $this->descriptionHtml = $achievement->getDescriptionHtml();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->evidences = new ArrayCollection();
        $this->reviews = new ArrayCollection();
        $portfolio->addShowcase($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPortfolio(): ?Portfolio
    {
        return $this->portfolio;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getAchievement(): ?PortfolioAchievement
    {
        return $this->achievement;
    }

    public function getAchievementRevision(): int
    {
        return $this->achievementRevision;
    }

    public function getConditionsHtml(): ?string
    {
        return $this->conditionsHtml;
    }

    public function setConditionsHtml(?string $conditionsHtml): static
    {
        $this->conditionsHtml = self::blankToNull($conditionsHtml);

        return $this;
    }

    public function getResourcesHtml(): ?string
    {
        return $this->resourcesHtml;
    }

    public function setResourcesHtml(?string $resourcesHtml): static
    {
        $this->resourcesHtml = self::blankToNull($resourcesHtml);

        return $this;
    }

    public function getAccessHtml(): ?string
    {
        return $this->accessHtml;
    }

    public function setAccessHtml(?string $accessHtml): static
    {
        $this->accessHtml = self::blankToNull($accessHtml);

        return $this;
    }

    public function getDescriptionHtml(): ?string
    {
        return $this->descriptionHtml;
    }

    public function setDescriptionHtml(?string $descriptionHtml): static
    {
        $this->descriptionHtml = self::blankToNull($descriptionHtml);

        return $this;
    }

    public function getState(): PortfolioState
    {
        return $this->state;
    }

    public function isValidated(): bool
    {
        return PortfolioState::Validated === $this->state;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getRequestedReviewer(): ?User
    {
        return $this->requestedReviewer;
    }

    public function setRequestedReviewer(?User $requestedReviewer): static
    {
        $this->requestedReviewer = $requestedReviewer;

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

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function getValidatedAt(): ?\DateTimeImmutable
    {
        return $this->validatedAt;
    }

    /** @return Collection<int, PortfolioEvidence> */
    public function getEvidences(): Collection
    {
        return $this->evidences;
    }

    public function addEvidence(PortfolioEvidence $evidence): static
    {
        if (!$this->evidences->contains($evidence)) {
            $this->evidences->add($evidence);
        }

        return $this;
    }

    public function removeEvidence(PortfolioEvidence $evidence): static
    {
        $this->evidences->removeElement($evidence);

        return $this;
    }

    /** @return Collection<int, PortfolioReview> */
    public function getReviews(): Collection
    {
        return $this->reviews;
    }

    public function addReview(PortfolioReview $review): static
    {
        if (!$this->reviews->contains($review)) {
            $this->reviews->add($review);
        }

        return $this;
    }

    public function getLastReview(): ?PortfolioReview
    {
        $first = $this->reviews->first();

        return false === $first ? null : $first;
    }

    /** A fiche written against an older revision of its réalisation than the current one. */
    public function isBehindAchievement(): bool
    {
        return null !== $this->achievement && $this->achievement->getRevision() !== $this->achievementRevision;
    }

    /** See PortfolioAchievement::touch() - the same rule, for the fiche's own four rubrics. */
    public function touch(): bool
    {
        $this->updatedAt = new \DateTimeImmutable();

        if (PortfolioState::Validated !== $this->state) {
            return false;
        }

        $this->enterReview();

        return true;
    }

    /**
     * Its réalisation changed (R11): a validated fiche goes back to « À valider » with it, since what
     * its recto prints is no longer what was validated.
     */
    public function followAchievement(): void
    {
        if (null === $this->achievement) {
            return;
        }

        $this->achievementRevision = $this->achievement->getRevision();

        if (PortfolioState::Validated === $this->state) {
            $this->enterReview();
        }
    }

    public function submit(): void
    {
        if (PortfolioState::Draft !== $this->state && PortfolioState::ToRework !== $this->state) {
            return;
        }

        $this->enterReview();
    }

    public function markValidated(): void
    {
        $this->state = PortfolioState::Validated;
        $this->validatedAt = new \DateTimeImmutable();
    }

    public function markToRework(): void
    {
        $this->state = PortfolioState::ToRework;
    }

    private function enterReview(): void
    {
        $this->state = PortfolioState::Submitted;
        ++$this->revision;
        $this->submittedAt = new \DateTimeImmutable();
        $this->updatedAt = $this->submittedAt;
    }

    private static function blankToNull(?string $value): ?string
    {
        return null === $value || '' === trim(strip_tags($value)) ? null : trim($value);
    }
}
