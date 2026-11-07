<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\LiveSessionStatus;
use App\Repository\QuizLiveSessionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A Kahoot-style live multiplayer game room, played through a single Live-mode QuizInstance (built
 * by App\Service\QuizLiveSessionService::createSession() via the same QuizInstantiationService used
 * for async launches - see QuizInstance's class docblock). One session per instance, no replay - see
 * design/design_campus_manager/reference/Générateur de quiz.dc.html, Turns 1t/1q/1u/1h/1i/1j.
 *
 * $roomCode is the contest's public address since 2026-11-07: /live/{code}, the target of the QR code
 * the waiting room shows (App\Controller\QuizLive\PlayController). A class still finds its contest
 * through the banner of its Quiz hub (QuizLiveSessionRepository::findActiveForProgram()).
 *
 * A contest belongs to a class or to none (its instance's program, null for none - design/validated/
 * quiz-live-public.md). $public opens it to whoever scans the QR code, account or not: a guest plays
 * under a pseudonym and a drawn avatar (QuizLiveParticipant::forGuest()). A contest with no class is
 * always public - nobody else could join it. $theme names its dress in App\Service\QuizLive\
 * LiveThemeCatalog; both are fixed at creation.
 */
#[ORM\Entity(repositoryClass: QuizLiveSessionRepository::class)]
#[ORM\Table(name: 'quiz_live_session')]
class QuizLiveSession
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: QuizInstance::class)]
    #[ORM\JoinColumn(name: 'quiz_instance_id', nullable: false, unique: true)]
    private ?QuizInstance $quizInstance = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'host_id', nullable: false)]
    private ?User $host = null;

    #[ORM\Column(name: 'room_code', length: 8, unique: true)]
    private ?string $roomCode = null;

    #[ORM\Column(name: 'is_public')]
    private bool $public = false;

    #[ORM\Column(length: 32)]
    private string $theme = 'pop';

    /**
     * Accounts the host took out of the room: they are not let back in. A guest taken out loses
     * their seat and may come back under another pseudonym - nothing ties two guests together.
     *
     * @var list<int>
     */
    #[ORM\Column(name: 'removed_user_ids', type: Types::JSON)]
    private array $removedUserIds = [];

    #[ORM\Column(length: 20, enumType: LiveSessionStatus::class)]
    private LiveSessionStatus $status = LiveSessionStatus::Lobby;

    // 0-based pointer into $quizInstance->getQuestions() - null while Lobby/Countdown.
    #[ORM\Column(name: 'current_question_index', nullable: true)]
    private ?int $currentQuestionIndex = null;

    // Server anchor for whatever the *current* status's countdown is (5s fixed for Countdown,
    // quizInstance->getSecondsPerQuestion() for Question) - reused across phases rather than one
    // column per phase, same "single anchor, client computes remaining" pattern as
    // QuizAttempt::isPastTimeLimit()/quiz_passation_controller.js. Null for phases with no timer.
    #[ORM\Column(name: 'phase_started_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $phaseStartedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'started_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'finished_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** @var Collection<int, QuizLiveParticipant> */
    #[ORM\OneToMany(mappedBy: 'session', targetEntity: QuizLiveParticipant::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $participants;

    public function __construct(QuizInstance $quizInstance, User $host, string $roomCode, bool $public = false, string $theme = 'pop')
    {
        $this->quizInstance = $quizInstance;
        $this->host = $host;
        $this->roomCode = $roomCode;
        // Nobody could join a contest of no class that was not public.
        $this->public = $public || null === $quizInstance->getProgram();
        $this->theme = $theme;
        $this->createdAt = new \DateTimeImmutable();
        $this->participants = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuizInstance(): ?QuizInstance
    {
        return $this->quizInstance;
    }

    public function getHost(): ?User
    {
        return $this->host;
    }

    public function getRoomCode(): ?string
    {
        return $this->roomCode;
    }

    public function isPublic(): bool
    {
        return $this->public;
    }

    public function getTheme(): string
    {
        return $this->theme;
    }

    /** The class the contest belongs to, if any. */
    public function getProgram(): ?Program
    {
        return $this->quizInstance?->getProgram();
    }

    public function isOver(): bool
    {
        return $this->status->isOver();
    }

    public function wasRemoved(User $user): bool
    {
        return \in_array((int) $user->getId(), $this->removedUserIds, true);
    }

    public function markRemoved(User $user): static
    {
        if (!$this->wasRemoved($user)) {
            $this->removedUserIds[] = (int) $user->getId();
        }

        return $this;
    }

    public function removeParticipant(QuizLiveParticipant $participant): static
    {
        $this->participants->removeElement($participant);

        return $this;
    }

    public function getStatus(): LiveSessionStatus
    {
        return $this->status;
    }

    public function setStatus(LiveSessionStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCurrentQuestionIndex(): ?int
    {
        return $this->currentQuestionIndex;
    }

    public function setCurrentQuestionIndex(?int $currentQuestionIndex): static
    {
        $this->currentQuestionIndex = $currentQuestionIndex;

        return $this;
    }

    public function getPhaseStartedAt(): ?\DateTimeImmutable
    {
        return $this->phaseStartedAt;
    }

    public function setPhaseStartedAt(?\DateTimeImmutable $phaseStartedAt): static
    {
        $this->phaseStartedAt = $phaseStartedAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeImmutable $finishedAt): static
    {
        $this->finishedAt = $finishedAt;

        return $this;
    }

    /** @return Collection<int, QuizLiveParticipant> */
    public function getParticipants(): Collection
    {
        return $this->participants;
    }

    public function addParticipant(QuizLiveParticipant $participant): static
    {
        if (!$this->participants->contains($participant)) {
            $this->participants->add($participant);
        }

        return $this;
    }

    // Resolves $currentQuestionIndex against the frozen instance's questions - null while
    // Lobby/Countdown/Finished/Cancelled, or if the pointer is out of range.
    public function getCurrentQuestion(): ?QuizInstanceQuestion
    {
        if (null === $this->currentQuestionIndex) {
            return null;
        }

        return $this->quizInstance->getQuestions()->toArray()[$this->currentQuestionIndex] ?? null;
    }

    // Server-authoritative lock, same "client ticks, server checks" split as
    // QuizAttempt::isPastTimeLimit()/quiz_passation_controller.js - a submitAnswer() POST arriving
    // after this is rejected even if the client's own countdown was slightly behind.
    //
    // **A student's « ajout de temps » is deliberately not applied here**, and this is the only
    // timed quiz surface where it is not. A concours live is one clock for the whole room: the host
    // opens a question, the projector counts it down and everybody's answer closes at the same
    // instant. Stretching one participant's phase would either hold the room or leave them
    // answering a question that has already been corrected on screen. What an aménagement is for -
    // composing an évaluation without the clock deciding the mark - is not what a live round is.
    public function isQuestionTimeUp(): bool
    {
        if (LiveSessionStatus::Question !== $this->status || null === $this->phaseStartedAt) {
            return false;
        }

        $seconds = $this->getCurrentQuestion()?->resolveSeconds($this->quizInstance->getSecondsPerQuestion());

        // No limit means the question never runs out - NOT that it ran out at once, which is what
        // the previous `?? 0` did the moment an untimed quiz reached the live board.
        if (null === $seconds) {
            return false;
        }

        return new \DateTimeImmutable() > $this->phaseStartedAt->modify(\sprintf('+%d seconds', $seconds));
    }
}
