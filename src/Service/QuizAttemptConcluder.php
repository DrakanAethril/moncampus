<?php

namespace App\Service;

use App\Entity\QuizAttempt;
use App\Entity\QuizAttemptAnswer;
use App\Enum\AttemptStatus;
use App\Enum\QuestionType;

/**
 * Closes a QuizAttempt and freezes its score - the end of both the web flow
 * (App\Controller\ProgramQuizAttemptController) and the mobile one (App\Controller\Api\QuizController),
 * whether the student pressed the last "Question suivante" or simply ran out of time.
 *
 * Shared rather than duplicated per controller: this is the one place that decides what a quiz is
 * out of, and two copies of that would eventually disagree on a student's mark.
 */
class QuizAttemptConcluder
{
    public function conclude(QuizAttempt $attempt, AttemptStatus $status): void
    {
        $attempt->setStatus($status);
        $attempt->setSubmittedAt(new \DateTimeImmutable());
        $attempt->setScore($this->earnedPoints($attempt), $this->availablePoints($attempt));
    }

    /**
     * Sum of what each answered question was frozen with. Unanswered questions contribute nothing -
     * an attempt cut short by the timer scores only what was actually done.
     */
    private function earnedPoints(QuizAttempt $attempt): float
    {
        $earned = array_sum(array_map(
            // Attempts answered before the score column existed only have is_correct to go on.
            static fn (QuizAttemptAnswer $a): float => $a->getScore() ?? (true === $a->getIsCorrect() ? 1.0 : 0.0),
            array_filter($attempt->getAttemptAnswers()->toArray(), static fn (QuizAttemptAnswer $a): bool => $a->isAnswered()),
        ));

        return round($earned, 2);
    }

    /**
     * What the attempt was out of. Every question is worth 1 point except a texte à trous, whose
     * barème the teacher sets (App\Entity\QuizQuestionDefinitionTrait::$points) - so this is the
     * question count for any quiz that never touched that field, and only diverges where a teacher
     * deliberately weighted a question. Summing points rather than counting questions is what keeps
     * a 2-point question from letting an attempt score above 100 %.
     */
    private function availablePoints(QuizAttempt $attempt): int
    {
        $total = 0.0;
        foreach ($attempt->getAttemptAnswers() as $attemptAnswer) {
            $question = $attemptAnswer->getInstanceQuestion();
            $total += QuestionType::TexteATrous === $question->getType() ? $question->getPoints() : 1.0;
        }

        // The column is an int and the screens read it as "x / 20": a fractional barème rounds up
        // rather than silently making a perfect attempt read as more than 100 %.
        return (int) ceil($total);
    }
}
