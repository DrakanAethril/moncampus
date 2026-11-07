<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\QuizLiveParticipantRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One player's presence in a QuizLiveSession: an account (any account Formatool knows - it keeps its
 * name and its avatar, it is never a guest) or, in a public contest, a guest (design/validated/
 * quiz-live-public.md) - a pseudonym, a drawn avatar and the hash of the token its browser holds,
 * nothing else. Unique per (session, student) for accounts - join is idempotent, so reconnecting
 * (app backgrounded, browser refresh, SSE drop) resumes the same row rather than creating a
 * duplicate - see QuizLiveSessionService::joinAccount(). MySQL lets the guests' null students
 * share the index.
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
    #[ORM\JoinColumn(name: 'student_id', nullable: true)]
    private ?User $student = null;

    // A guest's: the SHA-256 of the token its browser holds in a cookie (App\Service\QuizLive\
    // LiveGuestCookie). The token itself is never stored.
    #[ORM\Column(name: 'guest_token_hash', length: 64, unique: true, nullable: true)]
    private ?string $guestTokenHash = null;

    /**
     * A guest's drawn avatar, one of the twenty its browser drew with the shared generator
     * (public/serious-games/shared/avatar.js), normalised on the way in (App\SeriousGame\AvatarConfig).
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(name: 'guest_avatar', type: Types::JSON, nullable: true)]
    private ?array $guestAvatar = null;

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

    public function __construct(QuizLiveSession $session, ?User $student, string $displayName)
    {
        $this->session = $session;
        $this->student = $student;
        $this->displayName = $displayName;
        $this->joinedAt = new \DateTimeImmutable();
        $this->answers = new ArrayCollection();
    }

    /** @param array<string, mixed> $avatar */
    public static function forGuest(QuizLiveSession $session, string $pseudonym, array $avatar, string $tokenHash): self
    {
        $participant = new self($session, null, $pseudonym);
        $participant->guestAvatar = $avatar;
        $participant->guestTokenHash = $tokenHash;

        return $participant;
    }

    public function isGuest(): bool
    {
        return null === $this->student;
    }

    /** @return array<string, mixed>|null */
    public function getGuestAvatar(): ?array
    {
        return $this->guestAvatar;
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
