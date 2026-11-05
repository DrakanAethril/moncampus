<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\QuizTournamentDuelDraftRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a player has ticked (or typed) on a duel's question without pressing « Valider » yet
 * (design/validated/quiz-tournament.md §5.2): a QCM à réponses multiples' boxes, a numérique's
 * value. Saved at each change while the player's time runs; when it runs out, the draft **becomes
 * their answer**, dated at their deadline (QuizTournamentDuelEngine) - the boxes ticked count even
 * though nobody validated them.
 *
 * Never an answer before that: it does not say « a répondu », it closes nothing, it is graded only
 * once it becomes one. One per player and question, rewritten in place; it is deleted when it
 * becomes an answer or the player validates one.
 */
#[ORM\Entity(repositoryClass: QuizTournamentDuelDraftRepository::class)]
#[ORM\Table(name: 'quiz_tournament_duel_draft')]
#[ORM\UniqueConstraint(name: 'quiz_tournament_duel_draft_unique', columns: ['question_id', 'player_id'])]
class QuizTournamentDuelDraft
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

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $savedAt;

    public function __construct(QuizTournamentDuelQuestion $question, User $player)
    {
        $this->question = $question;
        $this->player = $player;
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

    /** @param array<array-key, int> $selectedChoiceIds normalised to a list */
    public function record(array $selectedChoiceIds, ?float $numericValue, ?string $numericUnit, \DateTimeImmutable $at): static
    {
        $this->selectedChoiceIds = array_values($selectedChoiceIds);
        $this->numericValue = $numericValue;
        $this->numericUnit = $numericUnit;
        $this->savedAt = $at;

        return $this;
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

    public function getSavedAt(): \DateTimeImmutable
    {
        return $this->savedAt;
    }
}
