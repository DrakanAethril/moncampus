<?php

declare(strict_types=1);

namespace App\Service\LearningPath;

use App\Entity\LearningPath;
use App\Entity\LearningPathEnrollment;
use App\Entity\LearningPathQuizAttempt;
use App\Entity\LearningPathStep;
use App\Entity\LearningPathStepVisit;
use App\Entity\QuizQuestion;
use App\Entity\User;
use App\Repository\LearningPathEnrollmentRepository;
use App\Repository\LearningPathQuizAttemptRepository;
use App\Repository\LearningPathStepVisitRepository;
use App\Service\QuizQuestionCompleteness;
use App\Service\VideoCueGrader;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What following a learning path writes (design/validated/cours-en-ligne.md, §10): the start, the
 * steps opened, the quiz attempts - and, once, the day everything was done.
 *
 * It decides nothing about who may open what: that is App\Service\LearningPath\LearningPathBoard's
 * reading, which the controller asks again at every door before it calls anything here.
 *
 * The quizzes are graded by the library's own rule - App\Service\QuizAnswerChecker, reached through
 * App\Service\VideoCueGrader, which already reads a posted answer to a *library* question for the
 * questions set inside a video. A path's quiz is that same thing: a library question, asked outside
 * a class. So a question cannot be right in a class quiz and wrong in a path.
 */
class LearningPathFollower
{
    public function __construct(
        private readonly LearningPathEnrollmentRepository $enrollments,
        private readonly LearningPathStepVisitRepository $visits,
        private readonly LearningPathQuizAttemptRepository $attempts,
        private readonly LearningPathBoard $board,
        private readonly QuizQuestionCompleteness $completeness,
        private readonly VideoCueGrader $grader,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** « Commencer le parcours »: the click that dates the follow-up. Asking twice changes nothing. */
    public function start(LearningPath $path, User $user): LearningPathEnrollment
    {
        $enrollment = $this->enrollments->findOneFor($path, $user);

        if (null === $enrollment) {
            $enrollment = new LearningPathEnrollment($path, $user);
            $this->entityManager->persist($enrollment);
            $this->entityManager->flush();
        }

        return $enrollment;
    }

    /**
     * Somebody opened a step: the first time is written, every time counts as activity. The one
     * route that shows a step calls this, so the trace has one author.
     */
    public function open(LearningPathEnrollment $enrollment, LearningPathStep $step): void
    {
        if (null === $this->visits->findOneBy(['enrollment' => $enrollment, 'step' => $step])) {
            $this->entityManager->persist(new LearningPathStepVisit($enrollment, $step));
        }

        $enrollment->touch();
        $this->entityManager->flush();
        $this->settle($enrollment);
    }

    /**
     * A new go at a validation quiz - or the one left unfinished, picked up where it stopped.
     *
     * The questions are drawn afresh at every new attempt, among the ones the quiz can actually
     * ask: a question still waiting for its image is left out, as it is everywhere else.
     */
    public function attempt(LearningPathEnrollment $enrollment, LearningPathStep $step): LearningPathQuizAttempt
    {
        $running = $this->attempts->findRunning($enrollment, $step);
        if (null !== $running) {
            return $running;
        }

        $questions = array_values(array_filter(
            $step->getQuizTemplate()?->getQuestions()->toArray() ?? [],
            fn (QuizQuestion $question): bool => null === $this->completeness->gapOf($question),
        ));
        shuffle($questions);

        $count = $step->getQuestionCount();
        if (null !== $count) {
            $questions = \array_slice($questions, 0, $count);
        }

        $attempt = new LearningPathQuizAttempt($enrollment, $step, array_map(
            static fn (QuizQuestion $question): array => ['id' => (int) $question->getId(), 'label' => mb_substr(trim(strip_tags((string) $question->getLabel())), 0, 300)],
            $questions,
        ));

        $this->entityManager->persist($attempt);
        $enrollment->touch();
        $this->entityManager->flush();

        return $attempt;
    }

    /**
     * The verdict of one posted answer. A question that has been deleted from the library since the
     * draw is counted as answered wrong rather than left to block the attempt for ever.
     *
     * @param array<array-key, mixed> $submitted the posted form, under the passation's own field names
     */
    public function answer(LearningPathQuizAttempt $attempt, int $index, ?QuizQuestion $question, array $submitted): bool
    {
        $correct = null !== $question && $this->grader->isCorrect(
            $question,
            $this->grader->answerRows($question),
            $submitted,
            $this->variablesFor($attempt, $question),
        );

        $attempt->record($index, $correct);
        if (null === $attempt->nextIndex()) {
            $attempt->finish();
        }

        $attempt->getEnrollment()->touch();
        $this->entityManager->flush();

        if ($attempt->isFinished()) {
            $this->settle($attempt->getEnrollment());
        }

        return $correct;
    }

    /** Ends an attempt that has nothing left to ask, and reads the path again. */
    public function close(LearningPathQuizAttempt $attempt): void
    {
        $attempt->finish();
        $attempt->getEnrollment()->touch();
        $this->entityManager->flush();
        $this->settle($attempt->getEnrollment());
    }

    /**
     * The values a calculée asks for in this attempt: the same on a reload, different at the next
     * attempt - which is what the attempt's id, used as the draw's seed, gives.
     *
     * @return array<string, float>
     */
    public function variablesFor(LearningPathQuizAttempt $attempt, QuizQuestion $question): array
    {
        return $this->grader->variablesFor($question, (int) $attempt->getEnrollment()->getUser()->getId(), (int) $attempt->getId());
    }

    /** Writes the end date the first time the path reads as completed - and never again. */
    private function settle(LearningPathEnrollment $enrollment): void
    {
        if (null !== $enrollment->getCompletedAt()) {
            return;
        }

        if ($this->board->progress($enrollment->getPath(), $enrollment)->completed) {
            $enrollment->markCompleted();
            $this->entityManager->flush();
        }
    }
}
