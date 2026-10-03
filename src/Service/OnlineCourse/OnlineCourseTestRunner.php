<?php

declare(strict_types=1);

namespace App\Service\OnlineCourse;

use App\Entity\OnlineCourse;
use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Repository\QuizQuestionRepository;
use App\Service\QuizQuestionCompleteness;
use App\Service\VideoCueGrader;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Taking a course's test (« Test » on its card): every question of the quiz its author linked,
 * as many times as wanted, in a new order each time - and at the end the score alone, never which
 * question was right (App\Controller\OnlineCourse\PublicCourseTestController says why).
 *
 * **Nothing is recorded.** A course is read without an account and so is its test: the run lives in
 * the reader's session (App\Service\OnlineCourse\OnlineCourseTestRun), one per course, and a new go
 * replaces it. No score reaches the gradebook, the campus game or the author - a path's quiz is
 * where an author follows people (App\Service\LearningPath\LearningPathFollower), and it asks for
 * an account.
 *
 * The questions are the library's own, graded by the library's own rule - App\Service\VideoCueGrader
 * over App\Service\QuizAnswerChecker, as a path's quiz and a question inside a video are - so a
 * question cannot be right in a class quiz and wrong here. A question still waiting for its image
 * is left out of the draw, as it is everywhere else.
 */
class OnlineCourseTestRunner
{
    private const string SESSION_PREFIX = 'online_course_test/';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly QuizQuestionRepository $questions,
        private readonly QuizQuestionCompleteness $completeness,
        private readonly VideoCueGrader $grader,
    ) {
    }

    /**
     * The run under way on this course, finished or not - or null when there is none, or when it
     * was drawn from another quiz than the one the course links now.
     */
    public function current(OnlineCourse $course): ?OnlineCourseTestRun
    {
        $run = OnlineCourseTestRun::fromArray($this->requestStack->getSession()->get($this->key($course)));
        $quiz = $course->getQuizTemplate();

        return null !== $run && null !== $quiz && $run->courseId === $course->getId() && $run->quizId === $quiz->getId() ? $run : null;
    }

    /** A new go: every question of the quiz, shuffled. The one before, if any, is forgotten. */
    public function start(OnlineCourse $course): OnlineCourseTestRun
    {
        $quiz = $course->getQuizTemplate() ?? throw new \LogicException('A course without a test cannot be tested.');

        $questions = array_values(array_filter(
            $quiz->getQuestions()->toArray(),
            fn (QuizQuestion $question): bool => null === $this->completeness->gapOf($question),
        ));
        shuffle($questions);

        $run = OnlineCourseTestRun::draw((int) $course->getId(), (int) $quiz->getId(), array_map(
            static fn (QuizQuestion $question): int => (int) $question->getId(),
            $questions,
        ), random_int(1, 2_147_483_647));

        $this->save($course, $run);

        return $run;
    }

    /**
     * The question drawn at this index - still a question of the course's quiz, since an id is only
     * ever trusted for what it was drawn as. Null for one deleted from the library since the draw.
     */
    public function question(OnlineCourse $course, OnlineCourseTestRun $run, int $index): ?QuizQuestion
    {
        $id = $run->questionId($index);
        $question = null === $id ? null : $this->questions->find($id);

        return $question instanceof QuizQuestion && $this->belongs($question, $course->getQuizTemplate()) ? $question : null;
    }

    /**
     * The verdict of one posted answer. A question deleted from the library since the draw is
     * counted wrong rather than left to block the run.
     *
     * @param array<array-key, mixed> $submitted the posted form, under the passation's own field names
     */
    public function answer(OnlineCourse $course, OnlineCourseTestRun $run, int $index, ?QuizQuestion $question, array $submitted): void
    {
        $correct = null !== $question && $this->grader->isCorrect(
            $question,
            $this->grader->answerRows($question),
            $submitted,
            $this->variablesFor($run, $question),
        );

        $run->record($index, $correct);
        $this->save($course, $run);
    }

    /** Ends a run with nothing left to ask. */
    public function close(OnlineCourse $course, OnlineCourseTestRun $run): void
    {
        $run->finish();
        $this->save($course, $run);
    }

    /**
     * The values a calculée asks for in this run: the same on a reload, different at the next go -
     * which the run's own random seed gives. There is no person to seed it with.
     *
     * @return array<string, float>
     */
    public function variablesFor(OnlineCourseTestRun $run, QuizQuestion $question): array
    {
        return $this->grader->variablesFor($question, 0, $run->seed);
    }

    private function belongs(QuizQuestion $question, ?QuizTemplate $quiz): bool
    {
        return null !== $quiz && $question->getQuizTemplate() === $quiz;
    }

    private function save(OnlineCourse $course, OnlineCourseTestRun $run): void
    {
        $this->requestStack->getSession()->set($this->key($course), $run->toArray());
    }

    private function key(OnlineCourse $course): string
    {
        return self::SESSION_PREFIX.$course->getId();
    }
}
