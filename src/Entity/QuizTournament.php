<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\QuestionType;
use App\Enum\QuizTournamentPalette;
use App\Enum\QuizTournamentStatus;
use App\Repository\QuizTournamentRepository;
use App\Service\QuizTournament\QuizTournamentEligibility;
use App\Service\QuizTournament\QuizTournamentMonogram;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A quiz tournament (design/validated/quiz-tournament.md): over a period, usually months, the
 * students its targeting reaches challenge each other to duels of questions drawn from ordinary
 * library quizzes, and a rating ranks them.
 *
 * - **Targeting** (StructureTargetsTrait): Sections, Tracks, Programs; who it reaches is read at
 *   every gesture (App\Service\StructureTargeting\StructureTargeting), never frozen.
 * - **Sources**: quizzes of the creating administrator's library; only the duel formats count
 *   (QuestionType::isPlayableInTournament()).
 * - Born **closed** ($openedAt null): invisible to everyone but the administrators until one opens
 *   it. Editable until its first duel; after that only the name, the end and the targeting move.
 * - Dressed by a name, a palette (QuizTournamentPalette) and an optional logo (a storage key in the
 *   uploads bucket) - nothing else of a theme is written anywhere.
 */
#[ORM\Entity(repositoryClass: QuizTournamentRepository::class)]
#[ORM\Table(name: 'quiz_tournament')]
class QuizTournament implements StructureTargetable
{
    use StructureTargetsTrait;

    public const int DEFAULT_QUESTIONS_PER_DUEL = 20;
    public const int DEFAULT_MAX_TRUE_FALSE = 5;
    public const int DEFAULT_SECONDS_PER_QUESTION = 30;
    public const int DEFAULT_SECONDS_PER_MULTI_QUESTION = 40;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $endsAt;

    #[ORM\Column(length: 20, enumType: QuizTournamentPalette::class)]
    private QuizTournamentPalette $palette;

    // The logo's key in the uploads bucket, null for the monogram drawn from the name.
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoKey = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $questionsPerDuel = self::DEFAULT_QUESTIONS_PER_DUEL;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $maxTrueFalsePerDuel = self::DEFAULT_MAX_TRUE_FALSE;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $secondsPerQuestion = self::DEFAULT_SECONDS_PER_QUESTION;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $secondsPerMultiQuestion = self::DEFAULT_SECONDS_PER_MULTI_QUESTION;

    /** @var Collection<int, QuizTemplate> */
    #[ORM\ManyToMany(targetEntity: QuizTemplate::class)]
    #[ORM\JoinTable(name: 'quiz_tournament_source')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    private Collection $sources;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $createdBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $updatedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $openedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $openedAt = null;

    public function __construct(string $name, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, QuizTournamentPalette $palette, User $createdBy)
    {
        $this->name = $name;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->palette = $palette;
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
        $this->sources = new ArrayCollection();
        $this->initStructureTargets();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(\DateTimeImmutable $startsAt): static
    {
        $this->startsAt = $startsAt;

        return $this;
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function setEndsAt(\DateTimeImmutable $endsAt): static
    {
        $this->endsAt = $endsAt;

        return $this;
    }

    public function getPalette(): QuizTournamentPalette
    {
        return $this->palette;
    }

    public function setPalette(QuizTournamentPalette $palette): static
    {
        $this->palette = $palette;

        return $this;
    }

    /** What stands in for the logo when there is none (§10.1), drawn from the name. */
    public function getMonogram(): string
    {
        return QuizTournamentMonogram::of($this->name);
    }

    public function getLogoKey(): ?string
    {
        return $this->logoKey;
    }

    public function setLogoKey(?string $logoKey): static
    {
        $this->logoKey = $logoKey;

        return $this;
    }

    public function getQuestionsPerDuel(): int
    {
        return $this->questionsPerDuel;
    }

    public function setQuestionsPerDuel(int $questionsPerDuel): static
    {
        $this->questionsPerDuel = $questionsPerDuel;

        return $this;
    }

    public function getMaxTrueFalsePerDuel(): int
    {
        return $this->maxTrueFalsePerDuel;
    }

    public function setMaxTrueFalsePerDuel(int $maxTrueFalsePerDuel): static
    {
        $this->maxTrueFalsePerDuel = $maxTrueFalsePerDuel;

        return $this;
    }

    public function getSecondsPerQuestion(): int
    {
        return $this->secondsPerQuestion;
    }

    public function setSecondsPerQuestion(int $secondsPerQuestion): static
    {
        $this->secondsPerQuestion = $secondsPerQuestion;

        return $this;
    }

    public function getSecondsPerMultiQuestion(): int
    {
        return $this->secondsPerMultiQuestion;
    }

    public function setSecondsPerMultiQuestion(int $secondsPerMultiQuestion): static
    {
        $this->secondsPerMultiQuestion = $secondsPerMultiQuestion;

        return $this;
    }

    /** The time a question of this type gets in a duel: longer for a QCM à réponses multiples. */
    public function secondsFor(QuestionType $type): int
    {
        return QuestionType::QcmMulti === $type ? $this->secondsPerMultiQuestion : $this->secondsPerQuestion;
    }

    /** @return Collection<int, QuizTemplate> */
    public function getSources(): Collection
    {
        return $this->sources;
    }

    public function addSource(QuizTemplate $quiz): static
    {
        if (!$this->sources->contains($quiz)) {
            $this->sources->add($quiz);
        }

        return $this;
    }

    public function removeSource(QuizTemplate $quiz): static
    {
        $this->sources->removeElement($quiz);

        return $this;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function markUpdated(User $by, \DateTimeImmutable $at): static
    {
        $this->updatedBy = $by;
        $this->updatedAt = $at;

        return $this;
    }

    public function isOpened(): bool
    {
        return null !== $this->openedAt;
    }

    public function getOpenedAt(): ?\DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function getOpenedBy(): ?User
    {
        return $this->openedBy;
    }

    /** Opening is the administrator's gesture that shows the tournament to the people it reaches. */
    public function open(User $by, \DateTimeImmutable $at): static
    {
        if (null === $this->openedAt) {
            $this->openedAt = $at;
            $this->openedBy = $by;
        }

        return $this;
    }

    public function isInProgress(\DateTimeImmutable $now): bool
    {
        return QuizTournamentEligibility::isInProgress($this->isOpened(), $this->startsAt, $this->endsAt, $now);
    }

    public function statusAt(\DateTimeImmutable $now): QuizTournamentStatus
    {
        return QuizTournamentStatus::of($this->isOpened(), $this->startsAt, $this->endsAt, $now);
    }

    public function hasEnded(\DateTimeImmutable $now): bool
    {
        return $now >= $this->endsAt;
    }

    /** The moment the standings are read at: now, or the end once it is over (frozen standings). */
    public function standingsMoment(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $this->hasEnded($now) ? $this->endsAt : $now;
    }
}
