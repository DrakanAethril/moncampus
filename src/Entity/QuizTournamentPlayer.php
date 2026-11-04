<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\QuizTournamentPlayerRepository;
use App\Service\QuizTournament\QuizTournamentElo;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A student's line in one tournament (design/validated/quiz-tournament.md §9): their rating and
 * their counters, the only stored figures of the tournament - they move in the duel's own
 * transaction, both players locked (§6.1).
 *
 * Created the first time the student takes part (a challenge, a round); an eligible student without
 * a row is « Pas encore joué » at QuizTournamentElo::START. A player who loses their eligibility
 * keeps the row, and with it their line in the standings.
 *
 * $displayedProgram is the last class shown next to their name: while they have a targeted active
 * formation the screens show that one and write it here, and once they have none this is what is
 * shown (§2, « La classe affichée »).
 */
#[ORM\Entity(repositoryClass: QuizTournamentPlayerRepository::class)]
#[ORM\Table(name: 'quiz_tournament_player')]
#[ORM\UniqueConstraint(name: 'quiz_tournament_player_unique', columns: ['tournament_id', 'user_id'])]
class QuizTournamentPlayer
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
    private User $user;

    #[ORM\Column]
    private int $rating = QuizTournamentElo::START;

    #[ORM\Column]
    private int $duels = 0;

    #[ORM\Column]
    private int $wins = 0;

    #[ORM\Column]
    private int $draws = 0;

    #[ORM\Column]
    private int $losses = 0;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Program $displayedProgram = null;

    // How many rounds made this player sit out - the first criterion of the next exemption (§8).
    #[ORM\Column]
    private int $roundExemptions = 0;

    // The last time a tournament screen saw them - what a round's « présents sur la page » reads.
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(QuizTournament $tournament, User $user)
    {
        $this->tournament = $tournament;
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTournament(): QuizTournament
    {
        return $this->tournament;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRating(): int
    {
        return $this->rating;
    }

    public function getDuels(): int
    {
        return $this->duels;
    }

    public function getWins(): int
    {
        return $this->wins;
    }

    public function getDraws(): int
    {
        return $this->draws;
    }

    public function getLosses(): int
    {
        return $this->losses;
    }

    /**
     * A finished duel's effect on this line: the new rating and one more win, draw or loss.
     *
     * @param float $outcome 1, 0.5 or 0 (QuizTournamentElo::outcome())
     */
    public function recordDuel(int $newRating, float $outcome): static
    {
        $this->rating = $newRating;
        ++$this->duels;
        match (true) {
            $outcome > 0.5 => ++$this->wins,
            $outcome < 0.5 => ++$this->losses,
            default => ++$this->draws,
        };

        return $this;
    }

    public function getDisplayedProgram(): ?Program
    {
        return $this->displayedProgram;
    }

    public function setDisplayedProgram(?Program $displayedProgram): static
    {
        $this->displayedProgram = $displayedProgram;

        return $this;
    }

    public function getRoundExemptions(): int
    {
        return $this->roundExemptions;
    }

    public function recordRoundExemption(): static
    {
        ++$this->roundExemptions;

        return $this;
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function markSeen(\DateTimeImmutable $at): static
    {
        $this->lastSeenAt = $at;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
