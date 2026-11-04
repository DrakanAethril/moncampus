<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

use App\Entity\QuizTournamentDuelQuestion;
use App\Enum\QuestionType;
use App\Service\QuizAnswerChecker;

/**
 * Grades an answer to a duel's question (design/validated/quiz-tournament.md §5.3) with Formatool's
 * own rule, QuizAnswerChecker, on the choices frozen in the duel
 * (QuizTournamentDuelQuestion::choicesForChecker()).
 *
 * A question is worth 0 to 1 point whatever its barème in the library: a duel is counted in
 * questions. The four duel formats are all-or-nothing on this platform - QuizAttemptGrader gives a
 * QCM à réponses multiples nothing unless the ticked set is exactly the right one, a numérique
 * nothing outside its tolerance - so a question earns 1 or 0; partial credit would only come with a
 * format the duel does not play. **No penalty**: a wrong answer earns 0, never less.
 */
class QuizTournamentDuelGrader
{
    public function __construct(private readonly QuizAnswerChecker $checker)
    {
    }

    /**
     * Does this answer fit the question - one of its choices for a QCM or a vrai/faux, at least one
     * and no repeat for a QCM à réponses multiples, a number for a numérique? Anything else is
     * refused before it is graded or stored.
     *
     * @param list<int> $choiceIds the question's own choice ids
     * @param list<int> $selected
     */
    public static function isWellFormed(QuestionType $type, array $choiceIds, array $selected, ?float $value): bool
    {
        $known = [] === array_diff($selected, $choiceIds);

        return match ($type) {
            QuestionType::Qcm, QuestionType::VraiFaux => 1 === \count($selected) && $known,
            QuestionType::QcmMulti => [] !== $selected && \count(array_unique($selected)) === \count($selected) && $known,
            QuestionType::Numerique => null !== $value && [] === $selected,
            default => false,
        };
    }

    /** @param list<int> $selected */
    public function points(QuizTournamentDuelQuestion $question, array $selected, ?float $value, ?string $unit): float
    {
        return $this->checker->isCorrect($question, $question->choicesForChecker(), $selected, numericValue: $value, numericUnit: $unit) ? 1.0 : 0.0;
    }
}
