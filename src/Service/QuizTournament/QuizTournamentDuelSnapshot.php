<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

use App\Enum\QuestionType;

/**
 * What a duel freezes of the library when it starts (design/validated/quiz-tournament.md §5.1), as
 * pure functions: the reserve the draw reads (QuizTournamentQuestionDraw), and a question's choices
 * as App\Entity\QuizTournamentDuelQuestion stores them.
 *
 * A choice gets a **local id**, its position in the question's own order (1, 2, …): what an answer
 * names, so that editing or deleting the library answers later changes nothing of a duel. The list
 * is stored in the order both players see (QuizTournamentQuestionDraw::choiceOrder(), from the duel's
 * seed and the question's rank).
 */
final class QuizTournamentDuelSnapshot
{
    /**
     * @param list<array{questionId: int, quizId: int, type: QuestionType}> $questions every question of the sources
     *
     * @return list<QuizTournamentDrawCandidate> the admitted reserve
     */
    public static function candidates(array $questions): array
    {
        $candidates = [];
        foreach ($questions as $question) {
            if (QuizTournamentQuestionDraw::admits($question['type'])) {
                $candidates[] = new QuizTournamentDrawCandidate($question['questionId'], $question['quizId'], QuestionType::VraiFaux === $question['type']);
            }
        }

        return $candidates;
    }

    /**
     * @param list<array{label: string, correct: bool}> $answers in the question's own order
     *
     * @return list<array{id: int, label: string, correct: bool}> in display order
     */
    public static function choices(QuestionType $type, array $answers, int $seed, int $rank): array
    {
        $byId = [];
        foreach ($answers as $index => $answer) {
            $byId[$index + 1] = ['id' => $index + 1, 'label' => $answer['label'], 'correct' => $answer['correct']];
        }

        return array_map(
            static fn (int $id): array => $byId[$id],
            QuizTournamentQuestionDraw::choiceOrder($type, array_keys($byId), $seed, $rank),
        );
    }

    /** A duel's seed: positive, and within the duel's unsigned INT column. */
    public static function newSeed(): int
    {
        return random_int(1, 2147483647);
    }
}
