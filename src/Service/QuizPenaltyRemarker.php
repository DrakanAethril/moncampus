<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizAttemptAnswer;
use App\Entity\QuizInstance;
use App\Repository\QuizAttemptRepository;

/**
 * Re-marks every copy of a quiz under the « note négative sur erreurs » settings it now carries -
 * run by « Modifier le quiz » after each save (App\Controller\ProgramQuizController::edit()).
 *
 * This is what makes the penalty editable at all. Without it, moving it would leave the copies
 * already handed in marked under the old rule and the ones still to come under the new one: the
 * same class sitting two different papers, which is a far worse outcome than a teacher having to
 * relaunch. Re-marking removes the asymmetry instead of forbidding the gesture.
 *
 * **Nothing is re-graded.** Whether an answer was right, and what it earned, were decided when the
 * student gave it and stay decided - QuizAttemptAnswer::earnedBeforePenalty() hands that raw mark
 * back, and only the penalty on top of it is recomputed. So a teacher who moves the penalty never
 * risks a student's selections being re-read by a grader that has changed since.
 *
 * Two things it deliberately leaves alone:
 *
 * - **An answer carrying no score at all.** Either the question was never reached - it is not
 *   penalised, and a copy cut short by the timer must not start owing points for what it never
 *   saw - or it predates the score column, and QuizAttemptConcluder still reads those from
 *   is_correct. Inventing a zero for them would turn a right answer into a penalised one.
 * - **When and how the copy was sat.** Its status, its hand-in instant and its surveillance count
 *   are facts about that sitting, so the total is rewritten through
 *   QuizAttemptConcluder::remark() rather than by concluding the attempt a second time.
 *
 * It is run on every save rather than only when a penalty field moved, and is idempotent by
 * construction: re-applying the same settings writes the same numbers. The count it returns is the
 * copies where *something the student can see* moved - a rename reports nothing because nothing
 * moved. The mark alone is not the test: the floor at zero can hold a total still while every
 * wrong line under it changes price, and that copy's correction has been re-marked all the same.
 */
class QuizPenaltyRemarker
{
    public function __construct(
        private readonly QuizAttemptRepository $attempts,
        private readonly QuizAttemptConcluder $concluder,
    ) {
    }

    /**
     * @return int how many copies moved - 0 when the settings did not
     */
    public function reapply(QuizInstance $instance): int
    {
        $changed = 0;

        foreach ($this->attempts->findAllForInstanceWithAnswers($instance) as $attempt) {
            $moved = false;

            foreach ($attempt->getAttemptAnswers() as $attemptAnswer) {
                $moved = $this->rescore($instance, $attemptAnswer) || $moved;
            }

            // An attempt still in progress has no total yet - its answers have just been put back
            // under one rule, and the mark is computed when it is handed in, as it always is. It is
            // not counted either: there is no mark of its own to have moved, and the student is
            // still composing.
            if (!$attempt->isConcluded()) {
                continue;
            }

            $before = $attempt->getCorrectCount();
            $this->concluder->remark($attempt);

            if ($moved || $before !== $attempt->getCorrectCount()) {
                ++$changed;
            }
        }

        return $changed;
    }

    /** @return bool whether this line's price actually moved */
    private function rescore(QuizInstance $instance, QuizAttemptAnswer $attemptAnswer): bool
    {
        $earned = $attemptAnswer->earnedBeforePenalty();
        $question = $attemptAnswer->getInstanceQuestion();

        if (null === $earned || null === $question) {
            return false;
        }

        // The very rule QuizAttemptGrader::score() applies when the answer is first given, asked
        // of the same object - so a copy re-marked here and a copy sat afterwards cannot end up
        // computed two different ways.
        $before = $attemptAnswer->getScore();
        $attemptAnswer->setScore($earned > 0.0 ? $earned : -$instance->penaltyFor($question->gradingPoints()));

        return $before !== $attemptAnswer->getScore();
    }
}
