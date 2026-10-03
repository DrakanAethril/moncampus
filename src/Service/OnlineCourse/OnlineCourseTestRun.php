<?php

declare(strict_types=1);

namespace App\Service\OnlineCourse;

/**
 * One go at a course's test (« Test » on its card): the questions drawn, in the order they are
 * asked, and the verdict of each one answered.
 *
 * It lives in the reader's session and nowhere else - a course is read without an account, so a
 * test is too, and **nothing of it is recorded**: no row, no score kept, no follow-up. A new go is
 * a new draw; the one before is simply forgotten. What this class holds is the arithmetic, kept
 * pure so that it can be tested without a session (App\Service\OnlineCourse\OnlineCourseTestRunner
 * stores it).
 *
 * The draw is frozen like a path attempt's (App\Entity\LearningPathQuizAttempt): the label of each
 * question is copied at the draw, so the correction reads what was asked even if the quiz is edited
 * in the library meanwhile.
 *
 * @phpstan-type DrawnQuestion array{id: int, label: string, answered: bool, correct: bool}
 * @phpstan-type StoredRun array{courseId: int, quizId: int, seed: int, questions: list<DrawnQuestion>, finished: bool}
 */
final class OnlineCourseTestRun
{
    /**
     * @param list<DrawnQuestion> $questions
     */
    private function __construct(
        public readonly int $courseId,
        public readonly int $quizId,
        public readonly int $seed,
        private array $questions,
        private bool $finished,
    ) {
    }

    /**
     * @param array<array-key, array{id: int, label: string}> $drawn the questions, in the order they will be asked
     */
    public static function draw(int $courseId, int $quizId, array $drawn, int $seed): self
    {
        return new self($courseId, $quizId, $seed, array_values(array_map(
            static fn (array $question): array => ['id' => $question['id'], 'label' => $question['label'], 'answered' => false, 'correct' => false],
            $drawn,
        )), false);
    }

    /**
     * Reads back what toArray() wrote - or null for anything else: a session is input like any
     * other, and a shape that does not match is a run that never was.
     */
    public static function fromArray(mixed $stored): ?self
    {
        if (!\is_array($stored) || !\is_int($stored['courseId'] ?? null) || !\is_int($stored['quizId'] ?? null)
            || !\is_int($stored['seed'] ?? null) || !\is_bool($stored['finished'] ?? null) || !\is_array($stored['questions'] ?? null)) {
            return null;
        }

        $questions = [];
        foreach ($stored['questions'] as $question) {
            if (!\is_array($question) || !\is_int($question['id'] ?? null) || !\is_string($question['label'] ?? null)
                || !\is_bool($question['answered'] ?? null) || !\is_bool($question['correct'] ?? null)) {
                return null;
            }
            $questions[] = ['id' => $question['id'], 'label' => $question['label'], 'answered' => $question['answered'], 'correct' => $question['correct']];
        }

        return new self($stored['courseId'], $stored['quizId'], $stored['seed'], $questions, $stored['finished']);
    }

    /** @return StoredRun */
    public function toArray(): array
    {
        return [
            'courseId' => $this->courseId,
            'quizId' => $this->quizId,
            'seed' => $this->seed,
            'questions' => $this->questions,
            'finished' => $this->finished,
        ];
    }

    /** @return list<DrawnQuestion> */
    public function questions(): array
    {
        return $this->questions;
    }

    public function questionId(int $index): ?int
    {
        return $this->questions[$index]['id'] ?? null;
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
     * Writes the verdict of one question and ends the run after the last one. A question already
     * answered keeps its first verdict: the back button followed by a second submit must not turn a
     * wrong answer into a right one.
     */
    public function record(int $index, bool $correct): void
    {
        if (!isset($this->questions[$index]) || $this->questions[$index]['answered'] || $this->finished) {
            return;
        }

        $this->questions[$index]['answered'] = true;
        $this->questions[$index]['correct'] = $correct;

        if (null === $this->nextIndex()) {
            $this->finished = true;
        }
    }

    /** Ends a run that has nothing left to ask - its quiz emptied since the draw. */
    public function finish(): void
    {
        $this->finished = true;
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }

    /** Out of 100, each question weighing the same; zero for a run with nothing asked. */
    public function scorePercent(): int
    {
        return 0 === $this->questionCount() ? 0 : (int) round(100 * $this->correctCount() / $this->questionCount());
    }
}
