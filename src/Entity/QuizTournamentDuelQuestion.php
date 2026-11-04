<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\QuestionType;
use App\Repository\QuizTournamentDuelQuestionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A question of a duel, frozen at the start (design/validated/quiz-tournament.md §5.1) - like
 * QuizInstanceQuestion, through QuizQuestionDefinitionTrait: editing a quiz during the tournament
 * changes no duel already drawn.
 *
 * Its choices are a snapshot too, in the order **both** players see them
 * (QuizTournamentQuestionDraw::choiceOrder()), each with a local id: what an answer names, and what
 * App\Service\QuizAnswerChecker reads through choicesForChecker(). A JSON list rather than rows: a
 * duel's choices are never queried, only shown and checked.
 *
 * $sourceQuiz is the question's « thème » on the defeat screen (its name kept in $sourceQuizName in
 * case the quiz goes); $sourceQuestion is what « already seen in the last three duels » compares.
 *
 * $servedAt is stamped once, when the question is served to both - reloading never restarts the
 * clock; $deadlineAt is when an answer stops being accepted.
 */
#[ORM\Entity(repositoryClass: QuizTournamentDuelQuestionRepository::class)]
#[ORM\Table(name: 'quiz_tournament_duel_question')]
#[ORM\UniqueConstraint(name: 'quiz_tournament_duel_question_rank', columns: ['duel_id', 'rank'])]
class QuizTournamentDuelQuestion implements QuizQuestionDefinition
{
    use QuizQuestionDefinitionTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: QuizTournamentDuel::class, inversedBy: 'questions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private QuizTournamentDuel $duel;

    // 0-based position in the duel. Backtick-quoted: RANK is a MySQL 8 reserved word.
    #[ORM\Column(name: '`rank`', type: Types::SMALLINT)]
    private int $rank;

    #[ORM\Column(length: 20, enumType: QuestionType::class)]
    private QuestionType $type;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $label = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageStorageKey = null;

    /** @var list<array{id: int, label: string, correct: bool}> in display order */
    #[ORM\Column(type: Types::JSON)]
    private array $choices = [];

    #[ORM\ManyToOne(targetEntity: QuizTemplate::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?QuizTemplate $sourceQuiz = null;

    #[ORM\Column(length: 255)]
    private string $sourceQuizName = '';

    #[ORM\ManyToOne(targetEntity: QuizQuestion::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?QuizQuestion $sourceQuestion = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $timeLimitSeconds;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $servedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deadlineAt = null;

    public function __construct(QuizTournamentDuel $duel, int $rank, QuestionType $type, int $timeLimitSeconds)
    {
        $this->duel = $duel;
        $this->rank = $rank;
        $this->type = $type;
        $this->timeLimitSeconds = $timeLimitSeconds;
        $duel->addQuestion($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDuel(): QuizTournamentDuel
    {
        return $this->duel;
    }

    public function getRank(): int
    {
        return $this->rank;
    }

    public function getType(): QuestionType
    {
        return $this->type;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getImageStorageKey(): ?string
    {
        return $this->imageStorageKey;
    }

    public function setImageStorageKey(?string $imageStorageKey): static
    {
        $this->imageStorageKey = $imageStorageKey;

        return $this;
    }

    /** @return list<array{id: int, label: string, correct: bool}> in display order */
    public function getChoices(): array
    {
        return $this->choices;
    }

    /** @param array<array-key, array{id: int, label: string, correct: bool}> $choices in display order, normalised to a list */
    public function setChoices(array $choices): static
    {
        $this->choices = array_values($choices);

        return $this;
    }

    /**
     * The choices in the shape App\Service\QuizAnswerChecker takes.
     *
     * @return list<array{id: int, correct: bool, orderIndex: int}>
     */
    public function choicesForChecker(): array
    {
        $rows = [];
        foreach ($this->choices as $index => $choice) {
            $rows[] = ['id' => $choice['id'], 'correct' => $choice['correct'], 'orderIndex' => $index];
        }

        return $rows;
    }

    public function getSourceQuiz(): ?QuizTemplate
    {
        return $this->sourceQuiz;
    }

    public function getSourceQuizName(): string
    {
        return $this->sourceQuizName;
    }

    public function setSource(QuizTemplate $quiz, QuizQuestion $question): static
    {
        $this->sourceQuiz = $quiz;
        $this->sourceQuizName = (string) $quiz->getName();
        $this->sourceQuestion = $question;

        return $this;
    }

    public function getSourceQuestion(): ?QuizQuestion
    {
        return $this->sourceQuestion;
    }

    public function getTimeLimitSeconds(): int
    {
        return $this->timeLimitSeconds;
    }

    public function getServedAt(): ?\DateTimeImmutable
    {
        return $this->servedAt;
    }

    public function getDeadlineAt(): ?\DateTimeImmutable
    {
        return $this->deadlineAt;
    }

    /** Stamped once: serving the question again (a reload) never restarts its clock. */
    public function serve(\DateTimeImmutable $at): static
    {
        if (null === $this->servedAt) {
            $this->servedAt = $at;
            $this->deadlineAt = $at->modify(\sprintf('+%d seconds', $this->timeLimitSeconds));
        }

        return $this;
    }

    public function acceptsAnswerAt(\DateTimeImmutable $at): bool
    {
        return null !== $this->deadlineAt && $at <= $this->deadlineAt;
    }
}
