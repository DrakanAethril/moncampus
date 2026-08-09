<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizInstanceAnswer;
use App\Entity\QuizInstanceQuestion;
use App\Entity\QuizQuestionDefinition;
use App\Enum\QuestionType;

/**
 * Whether a student's submitted answer(s) for one question are correct, and what they earn - see
 * App\Entity\QuizAttemptAnswer's class docblock (grading is computed once, at answer time, and
 * frozen). Grading rule per App\Enum\QuestionType:
 * - qcm / vrai_faux / image: exactly one selection, matching the question's single correct answer.
 * - qcm_multi: the selected set must exactly equal the correct set (no partial credit - picking 3
 *   of 4 correct answers, or 2 correct plus 1 wrong, both grade as incorrect).
 * - ordre: the submitted sequence must exactly match every answer's true QuizInstanceAnswer::$orderIndex.
 * - texte_a_trous: the only type with partial credit - each blank is graded on its own and the
 *   question's points are split equally between them (screens 2a-2d of design/design_handoff_quiz).
 *   isCorrect() stays "every blank right", so the green/red badges keep their all-or-nothing meaning.
 */
class QuizAttemptGrader
{
    public function __construct(private readonly QuizAnswerChecker $checker)
    {
    }

    /**
     * @param list<int>    $selectedInstanceAnswerIds in submission order (order only matters for "ordre" questions)
     * @param list<string> $blankResponses            what was typed/placed per blank - texte à trous only
     */
    public function isCorrect(QuizInstanceQuestion $question, array $selectedInstanceAnswerIds, array $blankResponses = []): bool
    {
        // A texte à trous has no answer rows at all - its correctness lives entirely in the blanks
        // config - so its collection is deliberately not touched here.
        $answers = QuestionType::TexteATrous === $question->getType() ? [] : $this->answerRows($question);

        return $this->checker->isCorrect($question, $answers, $selectedInstanceAnswerIds, $blankResponses);
    }

    /**
     * The points earned. Rounded to 2 decimals to match the column it is stored in, so summing an
     * attempt's answers can never drift from the value each row shows.
     *
     * @param list<int>    $selectedInstanceAnswerIds
     * @param list<string> $blankResponses
     */
    public function score(QuizInstanceQuestion $question, array $selectedInstanceAnswerIds, array $blankResponses = []): float
    {
        if (QuestionType::TexteATrous !== $question->getType()) {
            return $this->isCorrect($question, $selectedInstanceAnswerIds) ? 1.0 : 0.0;
        }

        $results = $this->blankResults($question, $blankResponses);
        if ([] === $results) {
            return 0.0;
        }

        return round($question->getPoints() * \count(array_filter($results)) / \count($results), 2);
    }

    /**
     * Per-blank correctness in text order - drives both the score above and the green/red rendering
     * of each blank on the entraînement correction screen (1m).
     *
     * @param list<string> $responses
     *
     * @return list<bool> empty when the question is not a texte à trous, or has no blank at all
     */
    public function blankResults(QuizQuestionDefinition $question, array $responses): array
    {
        return $this->checker->blankResults($question, $responses);
    }

    /**
     * The answers reduced to what grading needs. QuizAnswerChecker works on this rather than on the
     * entity so the library preview, which holds QuizAnswer instead of QuizInstanceAnswer, can call
     * the very same rule.
     *
     * @return list<array{id: int, correct: bool, orderIndex: int}>
     */
    private function answerRows(QuizInstanceQuestion $question): array
    {
        return array_values(array_map(
            static fn (QuizInstanceAnswer $answer): array => [
                'id' => $answer->getId(),
                'correct' => $answer->isCorrect(),
                // The template-defined order, never the shuffled order this student saw it in
                // (see QuizDrawService::orderAnswers()).
                'orderIndex' => $answer->getOrderIndex(),
            ],
            $question->getAnswers()->toArray(),
        ));
    }
}
