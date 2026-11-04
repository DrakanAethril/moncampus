<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

use App\Enum\QuestionType;

/**
 * A tournament's reserve of questions as the creation screen reads it (design/validated/quiz-tournament.md
 * §3), on primitives: the types of every question of the source quizzes in, what a duel may play and
 * what it ignores out.
 *
 * Only the duel formats count (QuizTournamentQuestionDraw::admits()); the screen says what the others
 * are (« 12 questions ignorées : apparier, ordre »). The reserve must allow a full duel - at least
 * $questionsPerDuel admitted questions, of which at least $questionsPerDuel − $maxTrueFalse are not
 * vrai/faux (QuizTournamentQuestionDraw::reserveShortfall()) - or the tournament is refused with the
 * count that is missing.
 */
final readonly class QuizTournamentReserve
{
    /**
     * @param array<string, int> $ignored question type value => questions, most frequent first
     */
    private function __construct(
        public int $admitted,
        public int $admittedNotTrueFalse,
        public array $ignored,
        public int $missing,
        public int $missingNotTrueFalse,
    ) {
    }

    /** @param list<QuestionType> $types the type of every question of the source quizzes */
    public static function of(array $types, int $questionsPerDuel, int $maxTrueFalse): self
    {
        $admitted = 0;
        $notTrueFalse = 0;
        $ignored = [];
        foreach ($types as $type) {
            if (QuizTournamentQuestionDraw::admits($type)) {
                ++$admitted;
                if (QuestionType::VraiFaux !== $type) {
                    ++$notTrueFalse;
                }
            } else {
                $ignored[$type->value] = ($ignored[$type->value] ?? 0) + 1;
            }
        }

        // Most frequent first, then in the enum's own order: what the screen names first is what
        // weighs most in what is left out.
        $order = array_flip(array_map(static fn (QuestionType $type): string => $type->value, QuestionType::cases()));
        uksort($ignored, static fn (string $a, string $b): int => [$ignored[$b], $order[$a]] <=> [$ignored[$a], $order[$b]]);

        $shortfall = QuizTournamentQuestionDraw::reserveShortfall($admitted, $notTrueFalse, $questionsPerDuel, $maxTrueFalse);

        return new self($admitted, $notTrueFalse, $ignored, $shortfall['missing'], $shortfall['missingNotTrueFalse']);
    }

    public function allowsAFullDuel(): bool
    {
        return 0 === $this->missing && 0 === $this->missingNotTrueFalse;
    }

    public function ignoredCount(): int
    {
        return array_sum($this->ignored);
    }

    /** @return list<QuestionType> the ignored types, most frequent first */
    public function ignoredTypes(): array
    {
        return array_map(static fn (string $value): QuestionType => QuestionType::from($value), array_keys($this->ignored));
    }
}
