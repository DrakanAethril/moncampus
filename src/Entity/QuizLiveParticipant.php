<?php

namespace App\Entity;

use App\Repository\QuizLiveParticipantRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One student's presence in a QuizLiveSession. Unique per (session, student) - join is idempotent,
 * so reconnecting (app backgrounded, browser refresh, SSE drop) resumes the same row rather than
 * creating a duplicate - see QuizLiveSessionService::join().
 */
#[ORM\Entity(repositoryClass: QuizLiveParticipantRepository::class)]
#[ORM\Table(name: 'quiz_live_participant')]
#[ORM\UniqueConstraint(name: 'uniq_quiz_live_participant_session_student', columns: ['session_id', 'student_id'])]
class QuizLiveParticipant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: QuizLiveSession::class, inversedBy: 'participants')]
    #[ORM\JoinColumn(name: 'session_id', nullable: false)]
    private ?QuizLiveSession $session = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false)]
    private ?User $student = null;

    // Editable "Nom de participant" (Turn 1r/1s) - defaults to student->getDisplayName(), purely
    // cosmetic (leaderboard label), never used for identity/auth.
    #[ORM\Column(name: 'display_name', length: 100)]
    private ?string $displayName = null;

    // Running Kahoot-style point total - deliberately unrelated to QuizAttempt's percentage-grade
    // shape, see App\Service\QuizLiveSessionService's scoring formula.
    #[ORM\Column]
    private int $score = 0;

    #[ORM\Column(name: 'joined_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $joinedAt;

    /** @var Collection<int, QuizLiveAnswer> */
    #[ORM\OneToMany(mappedBy: 'participant', targetEntity: QuizLiveAnswer::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $answers;

    public function __construct(QuizLiveSession $session, User $student, string $displayName)
    {
        $this->session = $session;
        $this->student = $student;
        $this->displayName = $displayName;
        $this->joinedAt = new \DateTimeImmutable();
        $this->answers = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSession(): ?QuizLiveSession
    {
        return $this->session;
    }

    public function getStudent(): ?User
    {
        return $this->student;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): static
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    public function addScore(int $points): static
    {
        $this->score += $points;

        return $this;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }

    /** @return Collection<int, QuizLiveAnswer> */
    public function getAnswers(): Collection
    {
        return $this->answers;
    }

    public function addAnswer(QuizLiveAnswer $answer): static
    {
        if (!$this->answers->contains($answer)) {
            $this->answers->add($answer);
        }

        return $this;
    }

    public function findAnswerFor(QuizInstanceQuestion $question): ?QuizLiveAnswer
    {
        foreach ($this->answers as $answer) {
            if ($answer->getInstanceQuestion() === $question) {
                return $answer;
            }
        }

        return null;
    }
}
