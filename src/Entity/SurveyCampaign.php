<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SurveyCampaignState;
use App\Repository\SurveyCampaignRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One launched wave of a survey - the counterpart of QuizInstance, and the object every screen of
 * the feature actually works on (surveys.md §4).
 *
 * It carries a frozen copy of the model's questions (SurveyCampaignQuestion) for two independent
 * reasons: results must survive an edit of the model, and a replay must ask *exactly* the same
 * questions. It targets people through the shared audience mechanism, which is why it implements
 * AudienceTargetable rather than inventing a rule of its own - no new audience rule is written for
 * surveys.
 *
 * Two properties are irreversible once target_frozen_at is set:
 *  - the frozen target itself (SurveyTarget), which is the denominator of the response rate and
 *    must not be recomputed on display, or the percentage moves on its own between two readings;
 *  - $anonymous, chosen at launch and shown to the respondent before they answer. A teacher
 *    flipping the flag after collection would betray a promise already made - and staff are not
 *    exempt: on an anonymous campaign *nobody* sees names, admins included.
 */
#[ORM\Entity(repositoryClass: SurveyCampaignRepository::class)]
#[ORM\Table(name: 'survey_campaign')]
#[ORM\UniqueConstraint(name: 'uniq_survey_campaign_series_wave', columns: ['series_id', 'wave_number'])]
class SurveyCampaign implements AudienceTargetable
{
    use AudienceTargetableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SurveySeries::class, inversedBy: 'campaigns')]
    #[ORM\JoinColumn(name: 'series_id', nullable: false)]
    private ?SurveySeries $series = null;

    #[ORM\Column(name: 'wave_number')]
    private int $waveNumber = 1;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';

    // Copied from the model at launch, editable until the campaign opens.
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: false)]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    // Null means open from the launch on.
    #[ORM\Column(name: 'opens_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $opensAt = null;

    // Null means no deadline.
    #[ORM\Column(name: 'closes_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closesAt = null;

    // Manual close, which beats both dates.
    #[ORM\Column(name: 'closed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column]
    private bool $anonymous = false;

    #[ORM\Column(name: 'results_visible_to_respondents')]
    private bool $resultsVisibleToRespondents = false;

    // The single moment the target became a fact. Written by SurveyLauncher and nowhere else.
    #[ORM\Column(name: 'target_frozen_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $targetFrozenAt = null;

    /** @var Collection<int, Program> */
    #[ORM\ManyToMany(targetEntity: Program::class)]
    #[ORM\JoinTable(name: 'survey_campaign_program')]
    private Collection $programs;

    #[ORM\Column(name: 'include_students')]
    private bool $includeStudents = true;

    #[ORM\Column(name: 'include_teachers')]
    private bool $includeTeachers = false;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'survey_campaign_manual_recipient')]
    private Collection $manualRecipients;

    /** @var Collection<int, SurveyCampaignQuestion> */
    #[ORM\OneToMany(mappedBy: 'campaign', targetEntity: SurveyCampaignQuestion::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['orderIndex' => 'ASC'])]
    private Collection $questions;

    public function __construct()
    {
        $this->programs = new ArrayCollection();
        $this->manualRecipients = new ArrayCollection();
        $this->questions = new ArrayCollection();
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSeries(): ?SurveySeries
    {
        return $this->series;
    }

    public function setSeries(?SurveySeries $series): static
    {
        $this->series = $series;

        return $this;
    }

    public function getWaveNumber(): int
    {
        return $this->waveNumber;
    }

    public function setWaveNumber(int $waveNumber): static
    {
        $this->waveNumber = $waveNumber;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
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

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    public function getOpensAt(): ?\DateTimeImmutable
    {
        return $this->opensAt;
    }

    public function setOpensAt(?\DateTimeImmutable $opensAt): static
    {
        $this->opensAt = $opensAt;

        return $this;
    }

    public function getClosesAt(): ?\DateTimeImmutable
    {
        return $this->closesAt;
    }

    public function setClosesAt(?\DateTimeImmutable $closesAt): static
    {
        $this->closesAt = $closesAt;

        return $this;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function setClosedAt(?\DateTimeImmutable $closedAt): static
    {
        $this->closedAt = $closedAt;

        return $this;
    }

    public function isAnonymous(): bool
    {
        return $this->anonymous;
    }

    /**
     * Only ever called before the launch. Once target_frozen_at is set the promise has been made
     * to the respondents, and setAnonymous() refuses rather than silently betraying it.
     */
    public function setAnonymous(bool $anonymous): static
    {
        if (null !== $this->targetFrozenAt && $anonymous !== $this->anonymous) {
            throw new \LogicException('The anonymity of a launched survey campaign is immutable.');
        }

        $this->anonymous = $anonymous;

        return $this;
    }

    public function isResultsVisibleToRespondents(): bool
    {
        return $this->resultsVisibleToRespondents;
    }

    public function setResultsVisibleToRespondents(bool $visible): static
    {
        $this->resultsVisibleToRespondents = $visible;

        return $this;
    }

    public function getTargetFrozenAt(): ?\DateTimeImmutable
    {
        return $this->targetFrozenAt;
    }

    public function setTargetFrozenAt(?\DateTimeImmutable $targetFrozenAt): static
    {
        $this->targetFrozenAt = $targetFrozenAt;

        return $this;
    }

    public function isLaunched(): bool
    {
        return null !== $this->targetFrozenAt;
    }

    /** @return Collection<int, Program> */
    public function getPrograms(): Collection
    {
        return $this->programs;
    }

    public function addProgram(Program $program): static
    {
        if (!$this->programs->contains($program)) {
            $this->programs->add($program);
        }

        return $this;
    }

    public function removeProgram(Program $program): static
    {
        $this->programs->removeElement($program);

        return $this;
    }

    public function isIncludeStudents(): bool
    {
        return $this->includeStudents;
    }

    public function setIncludeStudents(bool $includeStudents): static
    {
        $this->includeStudents = $includeStudents;

        return $this;
    }

    public function isIncludeTeachers(): bool
    {
        return $this->includeTeachers;
    }

    public function setIncludeTeachers(bool $includeTeachers): static
    {
        $this->includeTeachers = $includeTeachers;

        return $this;
    }

    /** @return Collection<int, User> */
    public function getManualRecipients(): Collection
    {
        return $this->manualRecipients;
    }

    public function addManualRecipient(User $user): static
    {
        if (!$this->manualRecipients->contains($user)) {
            $this->manualRecipients->add($user);
        }

        return $this;
    }

    public function removeManualRecipient(User $user): static
    {
        $this->manualRecipients->removeElement($user);

        return $this;
    }

    /** @return Collection<int, SurveyCampaignQuestion> */
    public function getQuestions(): Collection
    {
        return $this->questions;
    }

    public function addQuestion(SurveyCampaignQuestion $question): static
    {
        if (!$this->questions->contains($question)) {
            $this->questions->add($question);
        }

        return $this;
    }

    /**
     * The questions that actually ask something - Titre excluded.
     *
     * The single point of truth behind the five counts of surveys.md §7.13: the "12 questions ·
     * environ 5 minutes" line, the "Question 2 sur 12" counter, the Q1/Q2/Q3 numbering, the
     * per-question response rate and the wave comparison all call this instead of recounting on
     * their own. Getting one of them wrong is visible on screen, and always in the same way: a
     * total that is never reached.
     *
     * @return list<SurveyCampaignQuestion>
     */
    public function answerableQuestions(): array
    {
        return array_values(array_filter(
            $this->questions->toArray(),
            static fn (SurveyCampaignQuestion $question): bool => $question->getType()->isAnswerable(),
        ));
    }

    public function answerableQuestionCount(): int
    {
        return \count($this->answerableQuestions());
    }

    /**
     * Computed, never stored - same rule as QuizInstance::isOpenNow(), and for the same reason: a
     * stored state desynchronises the moment a date passes without anybody clicking.
     */
    public function state(?\DateTimeImmutable $now = null): SurveyCampaignState
    {
        if (null === $this->targetFrozenAt) {
            return SurveyCampaignState::Draft;
        }

        if (null !== $this->closedAt) {
            return SurveyCampaignState::Closed;
        }

        $now ??= new \DateTimeImmutable();

        if (null !== $this->opensAt && $now < $this->opensAt) {
            return SurveyCampaignState::Scheduled;
        }

        if (null !== $this->closesAt && $now > $this->closesAt) {
            return SurveyCampaignState::Closed;
        }

        return SurveyCampaignState::Open;
    }

    public function isOpenNow(?\DateTimeImmutable $now = null): bool
    {
        return SurveyCampaignState::Open === $this->state($now);
    }
}
