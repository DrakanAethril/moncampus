<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LearningPathQuizAttemptRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One go at a validation quiz of a learning path (design/validated/cours-en-ligne.md, §10).
 *
 * Its own table rather than App\Entity\QuizAttempt: that one belongs to a quiz *launched for a
 * class* (App\Entity\QuizInstance), and a path has no class. What is shared is the grading - the
 * library's own questions, checked by App\Service\QuizAnswerChecker through
 * App\Service\VideoCueGrader, exactly as a question answered inside a video is.
 *
 * `$questions` is the draw, **frozen**: which questions were asked, in which order, what each said,
 * and whether it was answered right. An attempt is training - as many as wanted, a new draw each
 * time - and its score is written when it ends, so editing the quiz in the library afterwards
 * rewrites no copy already handed in.
 *
 * @phpstan-type DrawnQuestion array{id: int, label: string, answered: bool, correct: bool}
 */
#[ORM\Entity(repositoryClass: LearningPathQuizAttemptRepository::class)]
#[ORM\Table(name: 'learning_path_quiz_attempt')]
#[ORM\Index(name: 'idx_learning_path_quiz_attempt_step', columns: ['enrollment_id', 'step_id'])]
class LearningPathQuizAttempt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: LearningPathEnrollment::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?LearningPathEnrollment $enrollment = null;

    #[ORM\ManyToOne(targetEntity: LearningPathStep::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?LearningPathStep $step = null;

    /** @var list<DrawnQuestion> */
    #[ORM\Column(type: Types::JSON)]
    private array $questions;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** Out of 100, written when the attempt ends. */
    #[ORM\Column(nullable: true)]
    private ?int $scorePercent = null;

    /**
     * @param array<array-key, array{id: int, label: string}> $drawn the questions, in the order they will be asked
     */
    public function __construct(LearningPathEnrollment $enrollment, LearningPathStep $step, array $drawn)
    {
        $this->enrollment = $enrollment;
        $this->step = $step;
        $this->questions = array_values(array_map(
            static fn (array $question): array => ['id' => $question['id'], 'label' => $question['label'], 'answered' => false, 'correct' => false],
            $drawn,
        ));
        $this->startedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEnrollment(): LearningPathEnrollment
    {
        \assert(null !== $this->enrollment);

        return $this->enrollment;
    }

    public function getStep(): LearningPathStep
    {
        \assert(null !== $this->step);

        return $this->step;
    }

    /** @return list<DrawnQuestion> */
    public function getQuestions(): array
    {
        return $this->questions;
    }

    public function questionCount(): int
    {
        return \count($this->questions);
    }

    public function correctCount(): int
    {
        return \count(array_filter($this->questions, static fn (array $question): bool => $question['correct']));
    }

    /** The index of the next question to ask, or null when every one has been answered. */
    public function nextIndex(): ?int
    {
        foreach ($this->questions as $index => $question) {
            if (!$question['answered']) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Writes the verdict of one question. A question already answered keeps its first verdict: the
     * back button followed by a second submit must not turn a wrong answer into a right one.
     */
    public function record(int $index, bool $correct): static
    {
        if (isset($this->questions[$index]) && !$this->questions[$index]['answered'] && null === $this->finishedAt) {
            $questions = $this->questions;
            $questions[$index]['answered'] = true;
            $questions[$index]['correct'] = $correct;
            $this->questions = $questions;
        }

        return $this;
    }

    public function isFinished(): bool
    {
        return null !== $this->finishedAt;
    }

    /**
     * Ends the attempt and writes its score: the share of questions answered right, each weighing
     * the same. An attempt with no question at all - its quiz was emptied meanwhile - scores zero
     * rather than dividing by it.
     */
    public function finish(): static
    {
        if (null === $this->finishedAt) {
            $this->finishedAt = new \DateTimeImmutable();
            $this->scorePercent = 0 === $this->questionCount() ? 0 : (int) round(100 * $this->correctCount() / $this->questionCount());
        }

        return $this;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getScorePercent(): ?int
    {
        return $this->scorePercent;
    }

    /** How long the attempt took, in seconds; null while it is running. */
    public function durationSeconds(): ?int
    {
        return null === $this->finishedAt ? null : max(0, $this->finishedAt->getTimestamp() - $this->startedAt->getTimestamp());
    }
}
