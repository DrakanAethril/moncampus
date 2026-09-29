<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PortfolioClaimState;
use App\Enum\PortfolioSetting;
use App\Enum\PortfolioState;
use App\Repository\PortfolioAchievementRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One réalisation professionnelle (a « situation professionnelle », SP) - one line of the E5
 * synthesis table, and the seed of an E6 fiche.
 *
 * **The student writes, a designated validateur decides** (R2). The validateur never edits the
 * text: they validate or send back, and retain or not each competency claimed.
 *
 * **A validation covers one revision** (R3). `revision` goes up each time the achievement enters
 * « À valider », and every App\Entity\PortfolioReview records the revision it decided on. A
 * validated achievement the student changes - its evidence included - goes back to « À valider »,
 * its claims back to « revendiquée »: the previous decision stays in the journal, the table stops
 * ticking it until somebody has read the new text.
 */
#[ORM\Entity(repositoryClass: PortfolioAchievementRepository::class)]
#[ORM\Table(name: 'portfolio_achievement')]
#[ORM\Index(name: 'portfolio_achievement_state_idx', columns: ['state', 'submitted_at'])]
class PortfolioAchievement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Portfolio::class, inversedBy: 'achievements')]
    #[ORM\JoinColumn(name: 'portfolio_id', nullable: false, onDelete: 'CASCADE')]
    private ?Portfolio $portfolio = null;

    #[ORM\Column(length: 255)]
    private string $title = '';

    #[ORM\Column(length: 20, enumType: PortfolioSetting::class)]
    private PortfolioSetting $setting = PortfolioSetting::Training;

    #[ORM\Column(name: 'starts_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startsOn = null;

    #[ORM\Column(name: 'ends_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endsOn = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $organisation = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $place = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $teamwork = false;

    /** With whom, and in which role - asked only when `teamwork` is ticked. */
    #[ORM\Column(name: 'team_note', type: Types::TEXT, nullable: true)]
    private ?string $teamNote = null;

    #[ORM\Column(name: 'description_html', type: Types::TEXT, nullable: true)]
    private ?string $descriptionHtml = null;

    #[ORM\Column(length: 20, enumType: PortfolioState::class)]
    private PortfolioState $state = PortfolioState::Draft;

    #[ORM\Column(options: ['default' => 0])]
    private int $revision = 0;

    /**
     * A validateur the student asked for, among those of their option. The line comes first in
     * that person's queue and stays open to the others - asking is not assigning.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'requested_reviewer_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $requestedReviewer = null;

    /** The work handed in this réalisation was started from, when « Ajouter à mon portfolio » was used. */
    #[ORM\ManyToOne(targetEntity: AssignmentSubmission::class)]
    #[ORM\JoinColumn(name: 'source_submission_id', nullable: true, onDelete: 'SET NULL')]
    private ?AssignmentSubmission $sourceSubmission = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'submitted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(name: 'validated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validatedAt = null;

    /** @var Collection<int, PortfolioClaim> */
    #[ORM\OneToMany(mappedBy: 'achievement', targetEntity: PortfolioClaim::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $claims;

    /** @var Collection<int, PortfolioEvidence> */
    #[ORM\OneToMany(mappedBy: 'achievement', targetEntity: PortfolioEvidence::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $evidences;

    /** @var Collection<int, PortfolioReview> */
    #[ORM\OneToMany(mappedBy: 'achievement', targetEntity: PortfolioReview::class)]
    #[ORM\OrderBy(['decidedAt' => 'DESC', 'id' => 'DESC'])]
    private Collection $reviews;

    public function __construct(Portfolio $portfolio)
    {
        $this->portfolio = $portfolio;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->claims = new ArrayCollection();
        $this->evidences = new ArrayCollection();
        $this->reviews = new ArrayCollection();
        $portfolio->addAchievement($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPortfolio(): ?Portfolio
    {
        return $this->portfolio;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = trim($title);

        return $this;
    }

    public function getSetting(): PortfolioSetting
    {
        return $this->setting;
    }

    public function setSetting(PortfolioSetting $setting): static
    {
        $this->setting = $setting;

        return $this;
    }

    public function getStartsOn(): ?\DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function setStartsOn(?\DateTimeImmutable $startsOn): static
    {
        $this->startsOn = $startsOn;

        return $this;
    }

    public function getEndsOn(): ?\DateTimeImmutable
    {
        return $this->endsOn;
    }

    public function setEndsOn(?\DateTimeImmutable $endsOn): static
    {
        $this->endsOn = $endsOn;

        return $this;
    }

    public function getOrganisation(): ?string
    {
        return $this->organisation;
    }

    public function setOrganisation(?string $organisation): static
    {
        $this->organisation = self::blankToNull($organisation);

        return $this;
    }

    public function getPlace(): ?string
    {
        return $this->place;
    }

    public function setPlace(?string $place): static
    {
        $this->place = self::blankToNull($place);

        return $this;
    }

    public function isTeamwork(): bool
    {
        return $this->teamwork;
    }

    public function setTeamwork(bool $teamwork): static
    {
        $this->teamwork = $teamwork;

        return $this;
    }

    public function getTeamNote(): ?string
    {
        return $this->teamNote;
    }

    public function setTeamNote(?string $teamNote): static
    {
        $this->teamNote = self::blankToNull($teamNote);

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

    public function getSourceSubmission(): ?AssignmentSubmission
    {
        return $this->sourceSubmission;
    }

    public function setSourceSubmission(?AssignmentSubmission $sourceSubmission): static
    {
        $this->sourceSubmission = $sourceSubmission;

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

    /** @return Collection<int, PortfolioClaim> */
    public function getClaims(): Collection
    {
        return $this->claims;
    }

    public function addClaim(PortfolioClaim $claim): static
    {
        if (!$this->claims->contains($claim)) {
            $this->claims->add($claim);
        }

        return $this;
    }

    public function removeClaim(PortfolioClaim $claim): static
    {
        $this->claims->removeElement($claim);

        return $this;
    }

    public function getClaimFor(ReferentialCompetency $competency): ?PortfolioClaim
    {
        foreach ($this->claims as $claim) {
            if ($claim->getCompetency()?->getId() === $competency->getId()) {
                return $claim;
            }
        }

        return null;
    }

    /** @return list<PortfolioClaim> the claims a validateur retained */
    public function getRetainedClaims(): array
    {
        return array_values($this->claims->filter(static fn (PortfolioClaim $claim): bool => PortfolioClaimState::Retained === $claim->getState())->toArray());
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

    /** @return Collection<int, PortfolioReview> newest first */
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

    /** The last decision that validated this achievement - the reference a changed text is compared to. */
    public function getLastValidation(): ?PortfolioReview
    {
        foreach ($this->reviews as $review) {
            if ($review->isValidation()) {
                return $review;
            }
        }

        return null;
    }

    public function getLastReview(): ?PortfolioReview
    {
        $first = $this->reviews->first();

        return false === $first ? null : $first;
    }

    /**
     * The student saved a change. A draft or a piece sent back just changes; a validated one goes
     * back to « À valider » as a new revision, its claims back to « revendiquée » (R3).
     *
     * @return bool whether the change reopened a validated achievement
     */
    public function touch(): bool
    {
        $this->updatedAt = new \DateTimeImmutable();

        if (PortfolioState::Validated !== $this->state) {
            return false;
        }

        $this->enterReview();

        return true;
    }

    /** « Soumettre à validation » - from a draft or a piece sent back. */
    public function submit(): void
    {
        if (PortfolioState::Draft !== $this->state && PortfolioState::ToRework !== $this->state) {
            return;
        }

        $this->enterReview();
    }

    /**
     * Applied by App\Service\Portfolio\PortfolioReviewer, after the claims were decided one by one.
     */
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

        foreach ($this->claims as $claim) {
            $claim->reopen();
        }
    }

    private static function blankToNull(?string $value): ?string
    {
        return null === $value || '' === trim($value) ? null : trim($value);
    }
}
