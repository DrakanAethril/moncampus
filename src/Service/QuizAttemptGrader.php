<?php

namespace App\Service;

use App\Entity\QuizInstanceAnswer;
use App\Entity\QuizInstanceQuestion;
use App\Enum\QuestionType;

/**
 * Whether a student's submitted answer(s) for one question are correct - see
 * App\Entity\QuizAttemptAnswer's class docblock (grading is computed once, at answer time, and
 * frozen). Grading rule per App\Enum\QuestionType:
 * - qcm / vrai_faux / image: exactly one selection, matching the question's single correct answer.
 * - qcm_multi: the selected set must exactly equal the correct set (no partial credit - picking 3
 *   of 4 correct answers, or 2 correct plus 1 wrong, both grade as incorrect).
 * - ordre: the submitted sequence must exactly match every answer's true QuizInstanceAnswer::$orderIndex.
 */
class QuizAttemptGrader
{
    /** @param list<int> $selectedInstanceAnswerIds in submission order (order only matters for "ordre" questions) */
    public function isCorrect(QuizInstanceQuestion $question, array $selectedInstanceAnswerIds): bool
    {
        return match ($question->getType()) {
            QuestionType::Qcm, QuestionType::VraiFaux, QuestionType::Image => $this->isCorrectSingle($question, $selectedInstanceAnswerIds),
            QuestionType::QcmMulti => $this->isCorrectMulti($question, $selectedInstanceAnswerIds),
            QuestionType::Ordre => $this->isCorrectOrder($question, $selectedInstanceAnswerIds),
        };
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
