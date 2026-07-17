<?php

namespace App\Entity;

use App\Enum\AttemptOrigin;
use App\Enum\AttemptStatus;
use App\Repository\QuizAttemptRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One student's pass at a QuizInstance - see design/design_campus_manager/README.md's "Générateur
 * de quiz" section, screens 1e (passation)/1m (correction). $status stays null while the attempt
 * is in progress (screen 1e) - only set once concluded, either normally (submitAttempt()) or
 * lazily once past the instance's time window/budget on the next request that touches it, same
 * "compute live, no cron" convention as Assignment::isLate() (see isPastTimeLimit()).
 *
 * Entraînement = unlimited attempts, a fresh $shuffleSeed (and so a fresh draw - see
 * App\Service\QuizDrawService) on every one of them, regardless of the instance's
 * $sameQuestionsForAll toggle (that toggle only ever means "same across students", never "same
 * across a single student's repeated practice attempts"). Évaluation = normally one attempt,
 * unless a teacher grants a retry later (App\Enum\AttemptOrigin::Relance) - "score retenu = la
 * dernière tentative" is a read-time concern (QuizAttemptRepository), not enforced here.
 */
#[ORM\Entity(repositoryClass: QuizAttemptRepository::class)]
#[ORM\Table(name: 'quiz_attempt')]
class QuizAttempt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: QuizInstance::class)]
    #[ORM\JoinColumn(name: 'quiz_instance_id', nullable: false)]
    private ?QuizInstance $quizInstance = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false)]
    private ?User $student = null;

    #[ORM\Column(name: 'attempt_number')]
    private int $attemptNumber = 1;

    #[ORM\Column(length: 20, enumType: AttemptStatus::class, nullable: true)]
    private ?AttemptStatus $status = null;

    #[ORM\Column(length: 20, enumType: AttemptOrigin::class)]
    private AttemptOrigin $origin = AttemptOrigin::Initiale;

    // Drives the deterministic draw/order (App\Service\QuizDrawService) - a fresh random int per
    // attempt, even when the resulting draw ends up ignoring it (sameQuestionsForAll = true uses
    // the QuizInstance's own id as the selection seed instead - see that service's docblock).
    #[ORM\Column(name: 'shuffle_seed')]
    private int $shuffleSeed = 0;

    #[ORM\Column(name: 'started_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'submitted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(name: 'correct_count', nullable: true)]
    private ?int $correctCount = null;

    #[ORM\Column(name: 'question_total', nullable: true)]
    private ?int $questionTotal = null;

    /** @var Collection<int, QuizAttemptAnswer> */
    #[ORM\OneToMany(mappedBy: 'attempt', targetEntity: QuizAttemptAnswer::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['orderIndex' => 'ASC'])]
    private Collection $attemptAnswers;

    public function __construct(QuizInstance $quizInstance, User $student)
    {
        $this->quizInstance = $quizInstance;
        $this->student = $student;
        $this->startedAt = new \DateTimeImmutable();
        $this->attemptAnswers = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuizInstance(): ?QuizInstance
    {
        return $this->quizInstance;
    }

    public function getStudent(): ?User
    {
        return $this->student;
    }

    public function getAttemptNumber(): int
    {
        return $this->attemptNumber;
    }

    public function setAttemptNumber(int $attemptNumber): static
    {
        $this->attemptNumber = $attemptNumber;

        return $this;
    }

    public function getStatus(): ?AttemptStatus
    {
        return $this->status;
    }

    public function setStatus(?AttemptStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isConcluded(): bool
    {
        return null !== $this->status;
    }

    // Compute-live time enforcement, same convention as Assignment::isLate() - no cron marks an
    // attempt Interrompu the instant its budget runs out; any request that touches it afterward
    // (ProgramQuizAttemptController) lazily finalizes it first. An already-concluded attempt is
    // never "past" anything - this only matters while still in progress.
    public function isPastTimeLimit(): bool
    {
        if ($this->isConcluded()) {
            return false;
        }

        $now = new \DateTimeImmutable();

        $globalMinutes = $this->quizInstance->getGlobalTimeMinutes();
        if (null !== $globalMinutes && $now > $this->startedAt->modify(\sprintf('+%d minutes', $globalMinutes))) {
            return true;
        }

        $closesAt = $this->quizInstance->getClosesAt();

        return null !== $closesAt && $now > $closesAt;
    }

    public function getOrigin(): AttemptOrigin
    {
        return $this->origin;
    }

    public function setOrigin(AttemptOrigin $origin): static
    {
        $this->origin = $origin;

        return $this;
    }

    public function getShuffleSeed(): int
    {
        return $this->shuffleSeed;
    }

    public function setShuffleSeed(int $shuffleSeed): static
    {
        $this->shuffleSeed = $shuffleSeed;

        return $this;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function setSubmittedAt(?\DateTimeImmutable $submittedAt): static
    {
        $this->submittedAt = $submittedAt;

        return $this;
    }

    public function getCorrectCount(): ?int
    {
        return $this->correctCount;
    }

    public function getQuestionTotal(): ?int
    {
        return $this->questionTotal;
    }

    public function setScore(int $correctCount, int $questionTotal): static
    {
        $this->correctCount = $correctCount;
        $this->questionTotal = $questionTotal;

        return $this;
    }

    // Null until setScore() has run (attempt concluded) - never divide-by-zero on an in-progress
    // attempt's questionTotal.
    public function getScorePercent(): ?float
    {
        if (null === $this->correctCount || null === $this->questionTotal || 0 === $this->questionTotal) {
            return null;
        }

        return round($this->correctCount / $this->questionTotal * 100, 1);
    }

    public function getScoreOn20(): ?float
    {
        $percent = $this->getScorePercent();

        return null !== $percent ? round($percent / 100 * 20, 1) : null;
    }

    /** @return Collection<int, QuizAttemptAnswer> */
    public function getAttemptAnswers(): Collection
    {
        return $this->attemptAnswers;
    }

    public function addAttemptAnswer(QuizAttemptAnswer $attemptAnswer): static
    {
        if (!$this->attemptAnswers->contains($attemptAnswer)) {
            $this->attemptAnswers->add($attemptAnswer);
        }

        return $this;
    }
}
