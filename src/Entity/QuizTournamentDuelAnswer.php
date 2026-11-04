<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\QuizTournamentDuelAnswerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One player's answer to one question of a duel (design/validated/quiz-tournament.md §5): what they
 * chose (choice ids of QuizTournamentDuelQuestion::$choices, or a number and its unit), the points it
 * earned (0 to 1, Formatool's partial credit, no penalty) and when. A question left unanswered has no
 * row and counts zero. One answer per player and question: the first one stands.
 */
#[ORM\Entity(repositoryClass: QuizTournamentDuelAnswerRepository::class)]
#[ORM\Table(name: 'quiz_tournament_duel_answer')]
#[ORM\UniqueConstraint(name: 'quiz_tournament_duel_answer_unique', columns: ['question_id', 'player_id'])]
class QuizTournamentDuelAnswer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: QuizTournamentDuelQuestion::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private QuizTournamentDuelQuestion $question;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $player;

    /** @var list<int> */
    #[ORM\Column(type: Types::JSON)]
    private array $selectedChoiceIds = [];

    #[ORM\Column(nullable: true)]
    private ?float $numericValue = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $numericUnit = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $points;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $answeredAt;

    /** @param array<array-key, int> $selectedChoiceIds normalised to a list */
    public function __construct(QuizTournamentDuelQuestion $question, User $player, array $selectedChoiceIds, ?float $numericValue, ?string $numericUnit, float $points, \DateTimeImmutable $answeredAt)
    {
        $this->question = $question;
        $this->player = $player;
        $this->selectedChoiceIds = array_values($selectedChoiceIds);
        $this->numericValue = $numericValue;
        $this->numericUnit = $numericUnit;
        $this->points = number_format($points, 2, '.', '');
        $this->answeredAt = $answeredAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuestion(): QuizTournamentDuelQuestion
    {
        return $this->question;
    }

    public function getPlayer(): User
    {
        return $this->player;
    }

    /** @return list<int> */
    public function getSelectedChoiceIds(): array
    {
        return $this->selectedChoiceIds;
    }

    public function getNumericValue(): ?float
    {
        return $this->numericValue;
    }

    public function getNumericUnit(): ?string
    {
        return $this->numericUnit;
    }

    public function getPoints(): float
    {
        return (float) $this->points;
    }

    public function getAnsweredAt(): \DateTimeImmutable
    {
        return $this->answeredAt;
    }
}
