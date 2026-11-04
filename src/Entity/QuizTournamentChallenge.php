<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\QuizTournamentChallengeStatus;
use App\Repository\QuizTournamentChallengeRepository;
use App\Service\QuizTournament\QuizTournamentChallengeRules;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * « A défie B » (design/validated/quiz-tournament.md §4): the challenge, then the room it opens.
 *
 * Pending for 24 hours ($expiresAt); refused or accepted by the challenged, without a reason. An
 * accepted challenge is a **room**, open 24 hours ($roomClosesAt): each player clicks « Prêt », kept
 * fresh by a heartbeat (QuizTournamentChallengeRules::isStillReady()), and the duel starts when both
 * are. Nothing is sent to anybody: the challenge is read in « Mes défis ».
 *
 * $ladder is stamped when the challenge is sent: was the challenged one of the five ranked players
 * nearest the challenger's rating at that moment? A ladder challenge that is refused or expires
 * counts for the challenger's ladder rule (§6.2) - which is what stops the chasers from refusing the
 * leader until they drop out.
 */
#[ORM\Entity(repositoryClass: QuizTournamentChallengeRepository::class)]
#[ORM\Table(name: 'quiz_tournament_challenge')]
#[ORM\Index(name: 'quiz_tournament_challenge_outgoing', columns: ['tournament_id', 'challenger_id', 'status'])]
#[ORM\Index(name: 'quiz_tournament_challenge_incoming', columns: ['tournament_id', 'challenged_id', 'status'])]
class QuizTournamentChallenge
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: QuizTournament::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private QuizTournament $tournament;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $challenger;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $challenged;

    #[ORM\Column(length: 20, enumType: QuizTournamentChallengeStatus::class)]
    private QuizTournamentChallengeStatus $status = QuizTournamentChallengeStatus::Pending;

    #[ORM\Column]
    private bool $ladder;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $respondedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $roomClosesAt = null;

    // The last « Prêt » heartbeat of each player, null when they are not ready.
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $challengerReadyAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $challengedReadyAt = null;

    #[ORM\OneToOne(targetEntity: QuizTournamentDuel::class)]
    #[ORM\JoinColumn(nullable: true, unique: true)]
    private ?QuizTournamentDuel $duel = null;

    public function __construct(QuizTournament $tournament, User $challenger, User $challenged, bool $ladder, \DateTimeImmutable $createdAt)
    {
        $this->tournament = $tournament;
        $this->challenger = $challenger;
        $this->challenged = $challenged;
        $this->ladder = $ladder;
        $this->createdAt = $createdAt;
        $this->expiresAt = QuizTournamentChallengeRules::expiresAt($createdAt);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTournament(): QuizTournament
    {
        return $this->tournament;
    }

    public function getChallenger(): User
    {
        return $this->challenger;
    }

    public function getChallenged(): User
    {
        return $this->challenged;
    }

    public function involves(User $user): bool
    {
        return $this->challenger === $user || $this->challenged === $user;
    }

    /** The stored status, as last written. Read statusAt() to know where the challenge stands. */
    public function getStatus(): QuizTournamentChallengeStatus
    {
        return $this->status;
    }

    /**
     * The status at this moment: a pending row past its expiry is expired, a room past its end
     * missed - and the tournament's end closes both, whatever is left of their 24 hours (§4.3).
     */
    public function statusAt(\DateTimeImmutable $now): QuizTournamentChallengeStatus
    {
        $ended = $this->tournament->hasEnded($now);

        return match (true) {
            QuizTournamentChallengeStatus::Pending === $this->status && ($ended || $now >= $this->expiresAt) => QuizTournamentChallengeStatus::Expired,
            QuizTournamentChallengeStatus::Accepted === $this->status && ($ended || (null !== $this->roomClosesAt && $now >= $this->roomClosesAt)) => QuizTournamentChallengeStatus::Missed,
            default => $this->status,
        };
    }

    /**
     * Writes what statusAt() already reads - the lazy expiry of « Mes défis »: a row is rewritten
     * when somebody touches it, no task needed. True when something changed.
     */
    public function settle(\DateTimeImmutable $now): bool
    {
        $status = $this->statusAt($now);
        if ($status === $this->status) {
            return false;
        }

        match ($status) {
            QuizTournamentChallengeStatus::Expired => $this->expire(),
            QuizTournamentChallengeStatus::Missed => $this->miss(),
            default => null,
        };

        return true;
    }

    public function opponentOf(User $user): ?User
    {
        return match ($user) {
            $this->challenger => $this->challenged,
            $this->challenged => $this->challenger,
            default => null,
        };
    }

    public function isPending(\DateTimeImmutable $now): bool
    {
        return QuizTournamentChallengeStatus::Pending === $this->statusAt($now);
    }

    public function isRoomOpen(\DateTimeImmutable $now): bool
    {
        return QuizTournamentChallengeStatus::Accepted === $this->statusAt($now);
    }

    public function isLadder(): bool
    {
        return $this->ladder;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getRespondedAt(): ?\DateTimeImmutable
    {
        return $this->respondedAt;
    }

    public function getRoomClosesAt(): ?\DateTimeImmutable
    {
        return $this->roomClosesAt;
    }

    public function accept(\DateTimeImmutable $at): static
    {
        $this->status = QuizTournamentChallengeStatus::Accepted;
        $this->respondedAt = $at;
        $this->roomClosesAt = QuizTournamentChallengeRules::roomClosesAt($at);

        return $this;
    }

    public function refuse(\DateTimeImmutable $at): static
    {
        $this->status = QuizTournamentChallengeStatus::Refused;
        $this->respondedAt = $at;

        return $this;
    }

    /** Nobody answered in time, or the tournament ended first. */
    public function expire(): static
    {
        $this->status = QuizTournamentChallengeStatus::Expired;

        return $this;
    }

    /** The two never met in the room, or the tournament ended first. */
    public function miss(): static
    {
        $this->status = QuizTournamentChallengeStatus::Missed;
        $this->clearReadiness();

        return $this;
    }

    public function markPlayed(QuizTournamentDuel $duel): static
    {
        $this->status = QuizTournamentChallengeStatus::Played;
        $this->duel = $duel;
        $this->clearReadiness();

        return $this;
    }

    public function getDuel(): ?QuizTournamentDuel
    {
        return $this->duel;
    }

    /** « Prêt », or its heartbeat. */
    public function markReady(User $player, \DateTimeImmutable $at): static
    {
        if ($player === $this->challenger) {
            $this->challengerReadyAt = $at;
        } elseif ($player === $this->challenged) {
            $this->challengedReadyAt = $at;
        }

        return $this;
    }

    public function clearReady(User $player): static
    {
        if ($player === $this->challenger) {
            $this->challengerReadyAt = null;
        } elseif ($player === $this->challenged) {
            $this->challengedReadyAt = null;
        }

        return $this;
    }

    public function isReady(User $player, \DateTimeImmutable $now): bool
    {
        return match ($player) {
            $this->challenger => QuizTournamentChallengeRules::isStillReady($this->challengerReadyAt, $now),
            $this->challenged => QuizTournamentChallengeRules::isStillReady($this->challengedReadyAt, $now),
            default => false,
        };
    }

    public function areBothReady(\DateTimeImmutable $now): bool
    {
        return $this->isRoomOpen($now) && $this->isReady($this->challenger, $now) && $this->isReady($this->challenged, $now);
    }

    private function clearReadiness(): void
    {
        $this->challengerReadyAt = null;
        $this->challengedReadyAt = null;
    }
}
