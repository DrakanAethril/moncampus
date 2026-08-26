<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The rule that decides which questions of a supervised attempt are worth a teacher's look.
 *
 * **Three conditions, and all three of them.** A question is marked *à vérifier* when an absence of
 * at least the quiz's threshold falls inside its display window, *and* the question was on screen
 * for at least 20 seconds, *and* the answer given is right.
 *
 * The three justify one another. The absence says something happened elsewhere; the duration says
 * there was time to find something there; the correctness says it was of use. Drop any one of them
 * and the noise explodes - drop correctness in particular and the device starts reporting the
 * student who was stuck *and* got it wrong, which is to say the honest one in difficulty. That is
 * the worst false positive available, because it aims at exactly the person one must not aim at.
 *
 * The 8-second threshold is the quiz's own (QuizInstance::$supervisionExitSeconds); the 20 seconds
 * are not. They are the physical floor of looking something up - opening a tab, phrasing the query,
 * reading a result, coming back - not a preference.
 *
 * Two facts stand on their own, outside the three conditions. A **paste** into a field: the text
 * necessarily comes from somewhere else. And a question **served again and again** while time piles
 * up on it: re-displaying used to be how one got a fresh countdown, and the count is a signal in
 * itself.
 *
 * Nothing here touches a mark, and no "cheated" boolean comes out of it. What comes out is a
 * sentence a teacher can say out loud in front of a student - which is the whole deliverable.
 *
 * A service with no dependency at all, called at the conclusion of an attempt and re-read at display
 * time. Never copied into a controller and never into a Twig template.
 */
class QuizSupervisionAssessor
{
    /** The floor under which looking something up is not physically possible. Not settable. */
    public const int MIN_DISPLAY_SECONDS = 20;

    /** How many displays of one question stop being a flaky connection and start being a signal. */
    public const int MIN_DISPLAY_COUNT = 3;

    /** Under this, an answer is exculpatory whatever the window did - nobody searches that fast. */
    public const int FAST_ANSWER_SECONDS = 5;

    public const string REASON_ABSENCE = 'quizSupervisionReasonAbsence';
    public const string REASON_PASTE = 'quizSupervisionReasonPaste';
    public const string REASON_REDISPLAYED = 'quizSupervisionReasonRedisplayed';

    /**
     * @param list<QuizSupervisionQuestion> $questions
     * @param int                           $exitThresholdSeconds the quiz's own, see the class docblock
     */
    public function assess(array $questions, int $exitThresholdSeconds): QuizSupervisionReport
    {
        $verdicts = [];
        $flagged = 0;
        $absences = 0;

        foreach ($questions as $question) {
            $reasons = $this->reasonsFor($question, $exitThresholdSeconds);
            $absences += \count($question->absencesMs);
            if ([] !== $reasons) {
                ++$flagged;
            }

            $verdicts[] = new QuizSupervisionVerdict(
                $question->position,
                [] !== $reasons,
                $reasons,
                $question->absencesMs,
                $question->elapsedMs,
                $question->displayCount,
                $question->isCorrect,
            );
        }

        return new QuizSupervisionReport($verdicts, $flagged, $absences, $this->answeredTooFast($questions));
    }

    /** @return list<string> empty when there is nothing to say about this question */
    private function reasonsFor(QuizSupervisionQuestion $question, int $exitThresholdSeconds): array
    {
        // A question never answered says nothing about anybody: the attempt simply stopped before
        // reaching it, and an absence sitting on it is not evidence of anything.
        if (null === $question->elapsedMs || null === $question->isCorrect) {
            return [];
        }

        $reasons = [];

        // Stands alone: pasted text comes from somewhere else, whatever the window did.
        if ($question->hasPaste) {
            $reasons[] = self::REASON_PASTE;
        }

        $displayedLongEnough = $question->elapsedMs >= self::MIN_DISPLAY_SECONDS * 1000;

        if ($displayedLongEnough
            && true === $question->isCorrect
            && $question->longestAbsenceMs() >= $exitThresholdSeconds * 1000
        ) {
            $reasons[] = self::REASON_ABSENCE;
        }

        if ($displayedLongEnough && $question->displayCount >= self::MIN_DISPLAY_COUNT) {
            $reasons[] = self::REASON_REDISPLAYED;
        }

        return $reasons;
    }

    /**
     * The second, less expected reading of the time: everything right, everything fast, hard
     * questions included. That is not "searched online" - one does not look an answer up in three
     * seconds - it is "knew the paper". A leak, a corrigé going round.
     *
     * It is reported as an observation and never counted among the questions to check: it
     * questions the paper, at the scale of a class, not the student in front of you.
     */
    private function answeredTooFast(array $questions): bool
    {
        $answered = array_values(array_filter(
            $questions,
            static fn (QuizSupervisionQuestion $q): bool => null !== $q->elapsedMs && null !== $q->isCorrect,
        ));

        if (\count($answered) < 3) {
            return false;
        }

        $sawHard = false;
        foreach ($answered as $question) {
            if (true !== $question->isCorrect || $question->elapsedMs >= self::FAST_ANSWER_SECONDS * 1000) {
                return false;
            }
            $sawHard = $sawHard || $question->isHard;
        }

        // Without a hard question in the set, "fast and right" is simply an easy quiz.
        return $sawHard;
    }
}
