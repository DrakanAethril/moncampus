<?php

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
 * $roomCode is stored for the URL slug/support/fallback only - joining is code-less in the web/
 * mobile UI (a banner in the student's Quiz hub, see QuizLiveSessionRepository::findActiveForProgram()),
 * never a manual-entry field.
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

    public function __construct(QuizInstance $quizInstance, User $host, string $roomCode)
    {
        $this->quizInstance = $quizInstance;
        $this->host = $host;
        $this->roomCode = $roomCode;
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
    public function isQuestionTimeUp(): bool
    {
        if (LiveSessionStatus::Question !== $this->status || null === $this->phaseStartedAt) {
            return false;
        }

        $seconds = $this->quizInstance->getSecondsPerQuestion() ?? 0;

        return new \DateTimeImmutable() > $this->phaseStartedAt->modify(\sprintf('+%d seconds', $seconds));
    }
}
