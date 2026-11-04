<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\QuizTournamentDuelOrigin;
use App\Repository\QuizTournamentDuelRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A duel (design/validated/quiz-tournament.md §5): two players, the same questions frozen at the
 * start (QuizTournamentDuelQuestion), served to both at once with a deadline the server holds.
 *
 * Player A is the challenger, or the higher rated of a round pair. $seed draws the questions and the
 * order of their choices (App\Service\QuizTournament\QuizTournamentQuestionDraw) - the same for both.
 * The ratings before and after are kept on the duel; the players' counters move in the same
 * transaction (QuizTournamentPlayer::recordDuel()).
 *
 * $ladderA / $ladderB are stamped at the start: was the opponent one of the five ranked players
 * nearest that player's rating at that moment? (§6.2, the ladder rule.)
 */
#[ORM\Entity(repositoryClass: QuizTournamentDuelRepository::class)]
#[ORM\Table(name: 'quiz_tournament_duel')]
#[ORM\Index(name: 'quiz_tournament_duel_started', columns: ['tournament_id', 'started_at'])]
class QuizTournamentDuel
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
    private User $playerA;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $playerB;

    #[ORM\Column(length: 20, enumType: QuizTournamentDuelOrigin::class)]
    private QuizTournamentDuelOrigin $origin;

    #[ORM\ManyToOne(targetEntity: QuizTournamentRound::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?QuizTournamentRound $round = null;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $seed;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2)]
    private string $scoreA = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2)]
    private string $scoreB = '0.00';

    #[ORM\Column]
    private int $ratingBeforeA;

    #[ORM\Column]
    private int $ratingBeforeB;

    #[ORM\Column(nullable: true)]
    private ?int $ratingAfterA = null;

    #[ORM\Column(nullable: true)]
    private ?int $ratingAfterB = null;

    #[ORM\Column]
    private bool $ladderA;

    #[ORM\Column]
    private bool $ladderB;

    /** @var Collection<int, QuizTournamentDuelQuestion> */
    #[ORM\OneToMany(mappedBy: 'duel', targetEntity: QuizTournamentDuelQuestion::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['rank' => 'ASC'])]
    private Collection $questions;

    public function __construct(
        QuizTournament $tournament,
        User $playerA,
        User $playerB,
        QuizTournamentDuelOrigin $origin,
        int $seed,
        \DateTimeImmutable $startedAt,
        int $ratingBeforeA,
        int $ratingBeforeB,
        bool $ladderA,
        bool $ladderB,
    ) {
        $this->tournament = $tournament;
        $this->playerA = $playerA;
        $this->playerB = $playerB;
        $this->origin = $origin;
        $this->seed = $seed;
        $this->startedAt = $startedAt;
        $this->ratingBeforeA = $ratingBeforeA;
        $this->ratingBeforeB = $ratingBeforeB;
        $this->ladderA = $ladderA;
        $this->ladderB = $ladderB;
        $this->questions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTournament(): QuizTournament
    {
        return $this->tournament;
    }

    public function getPlayerA(): User
    {
        return $this->playerA;
    }

    public function getPlayerB(): User
    {
        return $this->playerB;
    }

    public function involves(User $user): bool
    {
        return $this->playerA === $user || $this->playerB === $user;
    }

    public function opponentOf(User $user): ?User
    {
        return match ($user) {
            $this->playerA => $this->playerB,
            $this->playerB => $this->playerA,
            default => null,
        };
    }

    public function getOrigin(): QuizTournamentDuelOrigin
    {
        return $this->origin;
    }

    public function getRound(): ?QuizTournamentRound
    {
        return $this->round;
    }

    public function setRound(?QuizTournamentRound $round): static
    {
        $this->round = $round;

        return $this;
    }

    public function getSeed(): int
    {
        return $this->seed;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function isFinished(): bool
    {
        return null !== $this->endedAt;
    }

    public function getScoreA(): float
    {
        return (float) $this->scoreA;
    }

    public function getScoreB(): float
    {
        return (float) $this->scoreB;
    }

    public function scoreOf(User $user): ?float
    {
        return match ($user) {
            $this->playerA => $this->getScoreA(),
            $this->playerB => $this->getScoreB(),
            default => null,
        };
    }

    public function setScores(float $scoreA, float $scoreB): static
    {
        $this->scoreA = number_format($scoreA, 2, '.', '');
        $this->scoreB = number_format($scoreB, 2, '.', '');

        return $this;
    }

    public function getRatingBeforeA(): int
    {
        return $this->ratingBeforeA;
    }

    public function getRatingBeforeB(): int
    {
        return $this->ratingBeforeB;
    }

    public function getRatingAfterA(): ?int
    {
        return $this->ratingAfterA;
    }

    public function getRatingAfterB(): ?int
    {
        return $this->ratingAfterB;
    }

    /** The end of the duel: final scores and both new ratings, in the transaction that moves the players. */
    public function finish(\DateTimeImmutable $at, float $scoreA, float $scoreB, int $ratingAfterA, int $ratingAfterB): static
    {
        $this->setScores($scoreA, $scoreB);
        $this->endedAt = $at;
        $this->ratingAfterA = $ratingAfterA;
        $this->ratingAfterB = $ratingAfterB;

        return $this;
    }

    public function isLadderA(): bool
    {
        return $this->ladderA;
    }

    public function isLadderB(): bool
    {
        return $this->ladderB;
    }

    /** @return Collection<int, QuizTournamentDuelQuestion> */
    public function getQuestions(): Collection
    {
        return $this->questions;
    }

    public function addQuestion(QuizTournamentDuelQuestion $question): static
    {
        if (!$this->questions->contains($question)) {
            $this->questions->add($question);
        }

        return $this;
    }
}
