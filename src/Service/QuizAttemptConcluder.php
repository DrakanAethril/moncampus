<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizAttempt;
use App\Entity\QuizAttemptAnswer;
use App\Enum\AttemptStatus;

/**
 * Closes a QuizAttempt and freezes its score - the end of both the web flow
 * (App\Controller\ProgramQuizAttemptController) and the mobile one (App\Controller\Api\QuizController),
 * whether the student pressed the last "Question suivante" or simply ran out of time.
 *
 * Shared rather than duplicated per controller: this is the one place that decides what a quiz is
 * out of, and two copies of that would eventually disagree on a student's mark. The automatic
 * hand-in of a supervised évaluation goes through here too, rather than growing a second way of
 * closing an attempt.
 */
class QuizAttemptConcluder
{
    public function __construct(
        private readonly QuizSupervisionReportBuilder $supervisionReports,
    ) {
    }

    public function conclude(QuizAttempt $attempt, AttemptStatus $status): void
    {
        $attempt->setStatus($status);
        $attempt->setSubmittedAt(new \DateTimeImmutable());
        $attempt->setScore($this->earnedPoints($attempt), $this->availablePoints($attempt));

        // The rule is asked once here and re-read at display time - never copied. It changes
        // nothing about the mark just computed above: it counts what a teacher may want to look
        // at, and that is all it does.
        if ($attempt->getQuizInstance()->isSupervised()) {
            $attempt->setFlaggedCount($this->supervisionReports->build($attempt)->flaggedCount);
        }
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
     * What the attempt was out of. Every answer-row question is worth 1 point; the config-driven
     * types (texte à trous, zone, légende) carry a teacher-settable barème
     * (App\Entity\QuizQuestionDefinitionTrait::$points) - so this is the question count for any
     * quiz that never touched that field, and only diverges where a teacher deliberately weighted
     * a question. Summing points rather than counting questions is what keeps a 2-point question
     * from letting an attempt score above 100 %.
     */
    private function availablePoints(QuizAttempt $attempt): int
    {
        $total = 0.0;
        foreach ($attempt->getAttemptAnswers() as $attemptAnswer) {
            $question = $attemptAnswer->getInstanceQuestion();
            $total += $question->getType()->usesAnswerRows() ? 1.0 : $question->getPoints();
        }

        // The column is an int and the screens read it as "x / 20": a fractional barème rounds up
        // rather than silently making a perfect attempt read as more than 100 %.
        return (int) ceil($total);
    }
}
