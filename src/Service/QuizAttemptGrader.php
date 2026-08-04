<?php

namespace App\Service;

use App\Entity\QuizInstanceAnswer;
use App\Entity\QuizInstanceQuestion;
use App\Entity\QuizQuestionDefinition;
use App\Enum\QuestionType;
use App\Util\BlankTextParser;

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
    /**
     * @param list<int>    $selectedInstanceAnswerIds in submission order (order only matters for "ordre" questions)
     * @param list<string> $blankResponses            what was typed/placed per blank - texte à trous only
     */
    public function isCorrect(QuizInstanceQuestion $question, array $selectedInstanceAnswerIds, array $blankResponses = []): bool
    {
        return match ($question->getType()) {
            QuestionType::Qcm, QuestionType::VraiFaux, QuestionType::Image => $this->isCorrectSingle($question, $selectedInstanceAnswerIds),
            QuestionType::QcmMulti => $this->isCorrectMulti($question, $selectedInstanceAnswerIds),
            QuestionType::Ordre => $this->isCorrectOrder($question, $selectedInstanceAnswerIds),
            QuestionType::TexteATrous => $this->isCorrectBlanks($question, $blankResponses),
        };
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
        if (QuestionType::TexteATrous !== $question->getType()) {
            return [];
        }

        $ignoreCase = $question->isIgnoreCase();
        $tolerateTypo = $question->isTolerateTypo();
        $results = [];

        foreach ($question->getBlankAnswers() as $index => $variants) {
            // A blank the teacher left without any accepted answer can never be got right - grading
            // it as correct would hand out free points for an unfinished question.
            $results[] = [] !== $variants
                && BlankTextParser::matches($responses[$index] ?? '', $variants, $ignoreCase, $tolerateTypo);
        }

        return $results;
    }

    /** @param list<string> $responses */
    private function isCorrectBlanks(QuizInstanceQuestion $question, array $responses): bool
    {
        $results = $this->blankResults($question, $responses);

        return [] !== $results && !\in_array(false, $results, true);
    }

    private function isCorrectSingle(QuizInstanceQuestion $question, array $selectedIds): bool
    {
        if (1 !== \count($selectedIds)) {
            return false;
        }

        $correctId = $this->correctAnswerIds($question)[0] ?? null;

        return null !== $correctId && $selectedIds[0] === $correctId;
    }

    private function isCorrectMulti(QuizInstanceQuestion $question, array $selectedIds): bool
    {
        $correctIds = $this->correctAnswerIds($question);
        if ([] === $correctIds) {
            return false;
        }

        sort($selectedIds);
        sort($correctIds);

        return $selectedIds === $correctIds;
    }

    // The correct sequence is every answer sorted by its true (template-defined) order - never
    // the order it happened to be displayed in for this student (see QuizDrawService::orderAnswers()).
    private function isCorrectOrder(QuizInstanceQuestion $question, array $selectedIds): bool
    {
        $answers = $question->getAnswers()->toArray();
        usort($answers, static fn (QuizInstanceAnswer $a, QuizInstanceAnswer $b): int => $a->getOrderIndex() <=> $b->getOrderIndex());
        $correctSequence = array_map(static fn (QuizInstanceAnswer $a): int => $a->getId(), $answers);

        return $selectedIds === $correctSequence;
    }

    /** @return list<int> */
    private function correctAnswerIds(QuizInstanceQuestion $question): array
    {
        return array_values(array_map(
            static fn (QuizInstanceAnswer $a): int => $a->getId(),
            array_filter($question->getAnswers()->toArray(), static fn (QuizInstanceAnswer $a): bool => $a->isCorrect()),
        ));
    }
}
