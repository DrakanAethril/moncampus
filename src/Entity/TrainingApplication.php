<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TrainingApplicationDecision;
use App\Enum\TrainingApplicationElement;
use App\Enum\TrainingApplicationState;
use App\Repository\TrainingApplicationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A student's application to a practice offer (design_handoff_workflow_postulation, screens 8a-8e).
 *
 * It looks like a mail and is written like one, but it never travels: it is read on the platform by
 * a teacher who passes judgement on four elements - the mail, the CV, the cover letter, the
 * signature. When the four are validated, the student's school mailbox unlocks and real companies
 * become reachable.
 *
 * **A validation once acquired stays acquired.** Only refused elements go back for review, and the
 * decisions carry the version they were taken on, so a student who fixes their cover letter is not
 * asked to prove their CV again. That rule is the reason decisions live in their own table rather
 * than as four columns here.
 */
#[ORM\Entity(repositoryClass: TrainingApplicationRepository::class)]
#[ORM\Table(name: 'training_application')]
#[ORM\Index(name: 'idx_training_application_student', columns: ['student_id'])]
#[ORM\Index(name: 'idx_training_application_state', columns: ['state'])]
class TrainingApplication
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $student = null;

    #[ORM\ManyToOne(targetEntity: TrainingOffer::class)]
    #[ORM\JoinColumn(name: 'offer_id', nullable: false, onDelete: 'CASCADE')]
    private ?TrainingOffer $offer = null;

    #[ORM\Column(length: 30, enumType: TrainingApplicationState::class)]
    private TrainingApplicationState $state = TrainingApplicationState::Received;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, TrainingApplicationVersion>
     *
     * One per submission: the first send, then one per resend. Kept whole so screen 8d can put v2
     * next to v1 - a validator who asked for a shorter paragraph wants to see what changed.
     */
    #[ORM\OneToMany(mappedBy: 'application', targetEntity: TrainingApplicationVersion::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['number' => 'ASC'])]
    private Collection $versions;

    /** @var Collection<int, TrainingApplicationReview> */
    #[ORM\OneToMany(mappedBy: 'application', targetEntity: TrainingApplicationReview::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['decidedAt' => 'ASC'])]
    private Collection $reviews;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->versions = new ArrayCollection();
        $this->reviews = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStudent(): ?User
    {
        return $this->student;
    }

    public function setStudent(?User $student): static
    {
        $this->student = $student;

        return $this;
    }

    public function getOffer(): ?TrainingOffer
    {
        return $this->offer;
    }

    public function setOffer(?TrainingOffer $offer): static
    {
        $this->offer = $offer;

        return $this;
    }

    public function getState(): TrainingApplicationState
    {
        return $this->state;
    }

    public function setState(TrainingApplicationState $state): static
    {
        $this->state = $state;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, TrainingApplicationVersion> */
    public function getVersions(): Collection
    {
        return $this->versions;
    }

    public function addVersion(TrainingApplicationVersion $version): static
    {
        if (!$this->versions->contains($version)) {
            $this->versions->add($version);
            $version->setApplication($this);
        }

        return $this;
    }

    public function getCurrentVersion(): ?TrainingApplicationVersion
    {
        $current = null;

        foreach ($this->versions as $version) {
            if (null === $current || $version->getNumber() > $current->getNumber()) {
                $current = $version;
            }
        }

        return $current;
    }

    public function getVersionNumber(): int
    {
        return $this->getCurrentVersion()?->getNumber() ?? 1;
    }

    /** @return Collection<int, TrainingApplicationReview> */
    public function getReviews(): Collection
    {
        return $this->reviews;
    }

    public function addReview(TrainingApplicationReview $review): static
    {
        if (!$this->reviews->contains($review)) {
            $this->reviews->add($review);
            $review->setApplication($this);
        }

        return $this;
    }

    /**
     * The standing verdict on one element: the last decision taken on it, whichever version it was
     * taken on. This is where "acquired stays acquired" actually lives.
     */
    public function getReviewFor(TrainingApplicationElement $element): ?TrainingApplicationReview
    {
        $latest = null;

        foreach ($this->reviews as $review) {
            if ($review->getElement() !== $element) {
                continue;
            }

            if (null === $latest || $review->getDecidedAt() > $latest->getDecidedAt()) {
                $latest = $review;
            }
        }

        return $latest;
    }

    public function isValidated(TrainingApplicationElement $element): bool
    {
        return TrainingApplicationDecision::Validated === $this->getReviewFor($element)?->getDecision();
    }

    /** How many of the four elements are through - the "x / 4" of screens 8a and 8d. */
    public function validatedCount(): int
    {
        $count = 0;

        foreach (TrainingApplicationElement::all() as $element) {
            $count += $this->isValidated($element) ? 1 : 0;
        }

        return $count;
    }

    public function isComplete(): bool
    {
        return \count(TrainingApplicationElement::all()) === $this->validatedCount();
    }

    /**
     * Is this element waiting on a validator? Either nobody has looked at it, or it was refused on
     * an earlier version and the student has since resent - which is what "corrigée en v2" means on
     * screen 8d, and what stops screen 8a from still shouting "correction demandée" after a resend.
     */
    public function isAwaitingReview(TrainingApplicationElement $element): bool
    {
        $review = $this->getReviewFor($element);

        if (null === $review) {
            return true;
        }

        return TrainingApplicationDecision::Refused === $review->getDecision()
            && $review->getVersionNumber() < $this->getVersionNumber();
    }

    /** Refused *and* not corrected since: the ball is still in the student's court. */
    public function isRefused(TrainingApplicationElement $element): bool
    {
        $review = $this->getReviewFor($element);

        return null !== $review
            && TrainingApplicationDecision::Refused === $review->getDecision()
            && $review->getVersionNumber() >= $this->getVersionNumber();
    }

    /** @return list<TrainingApplicationElement> the elements the student has to fix */
    public function refusedElements(): array
    {
        $refused = [];

        foreach (TrainingApplicationElement::all() as $element) {
            if ($this->isRefused($element)) {
                $refused[] = $element;
            }
        }

        return $refused;
    }
}
