<?php

declare(strict_types=1);

namespace App\Controller\LearningPath;

use App\Attribute\RequiresFeature;
use App\Entity\LearningPathQuizAttempt;
use App\Entity\QuizQuestion;
use App\Enum\Feature;
use App\Repository\LearningPathEnrollmentRepository;
use App\Repository\LearningPathQuizAttemptRepository;
use App\Repository\LearningPathRepository;
use App\Repository\QuizQuestionRepository;
use App\Service\LearningPath\LearningPathBoard;
use App\Service\LearningPath\LearningPathFollower;
use App\Service\PostValue;
use App\Service\QuizQuestionTakeView;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A validation quiz of a learning path, taken (design/validated/cours-en-ligne.md, §10): one
 * question at a time, the correction at the end, as many attempts as wanted with a new draw each
 * time, the best score kept.
 *
 * The questions are the library's own, drawn by the partials the passation already uses and graded
 * by the checker it already uses (App\Service\LearningPath\LearningPathFollower). Nothing here
 * reaches the gradebook or the campus game: a path's quiz opens the next step, and that is all it
 * does.
 *
 * Every action asks the rule again at the door, like the step itself: an attempt is started and
 * answered only on a step the person may open.
 */
#[RequiresFeature(Feature::LearningPaths)]
class PathQuizController extends AbstractController
{
    use PathFollowingTrait;

    public const string CSRF_TOKEN_ID = 'learning_path_quiz';

    #[Route(path: '/paths/{id}/steps/{number}/attempts', name: 'app_learning_path_attempt_start', requirements: ['id' => '\d+', 'number' => '\d+'], methods: ['POST'])]
    public function start(int $id, int $number, Request $request, LearningPathRepository $paths, LearningPathEnrollmentRepository $enrollments, LearningPathBoard $board, LearningPathFollower $follower): Response
    {
        $path = $this->findPath($id, $paths);
        $this->assertCsrf($request);
        $found = $this->stepOrRedirect($path, $number, $enrollments, $board);
        if ($found instanceof RedirectResponse) {
            return $found;
        }

        [$enrollment, , $view] = $found;
        if (!$view->step->isQuiz()) {
            throw $this->createNotFoundException();
        }

        $attempt = $follower->attempt($enrollment, $view->step);

        return $this->redirectToRoute('app_learning_path_attempt', ['id' => $path->getId(), 'number' => $number, 'attemptId' => $attempt->getId()]);
    }

    #[Route(path: '/paths/{id}/steps/{number}/attempts/{attemptId}', name: 'app_learning_path_attempt', requirements: ['id' => '\d+', 'number' => '\d+', 'attemptId' => '\d+'], methods: ['GET', 'POST'])]
    public function attempt(int $id, int $number, int $attemptId, Request $request, LearningPathRepository $paths, LearningPathEnrollmentRepository $enrollments, LearningPathQuizAttemptRepository $attempts, QuizQuestionRepository $questions, LearningPathBoard $board, LearningPathFollower $follower): Response
    {
        $path = $this->findPath($id, $paths);
        $found = $this->stepOrRedirect($path, $number, $enrollments, $board);
        if ($found instanceof RedirectResponse) {
            return $found;
        }

        [$enrollment, , $view] = $found;
        $attempt = $attempts->find($attemptId);
        if (!$attempt instanceof LearningPathQuizAttempt || $attempt->getEnrollment() !== $enrollment || $attempt->getStep() !== $view->step) {
            throw $this->createNotFoundException();
        }

        $here = ['id' => $path->getId(), 'number' => $number, 'attemptId' => $attempt->getId()];

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request);

            // The index is posted with the answer and checked against the attempt's own: a form
            // left open in another tab answers the question it was drawn for, or nothing.
            $posted = PostValue::string($request, 'index');
            $index = ctype_digit($posted) ? (int) $posted : -1;
            if (!$attempt->isFinished() && $index === $attempt->nextIndex()) {
                $follower->answer($attempt, $index, $this->questionAt($attempt, $index, $questions), $request->request->all());
            }

            return $this->redirectToRoute('app_learning_path_attempt', $here);
        }

        $index = $attempt->nextIndex();

        // An attempt with nothing left to ask - or with nothing to ask at all, its quiz having been
        // emptied since the draw - is closed here rather than left open for ever.
        if (null === $index && !$attempt->isFinished()) {
            $follower->close($attempt);
        }

        if ($attempt->isFinished() || null === $index) {
            $progress = $board->progress($path, $enrollment);
            $view = $progress->viewOf($number) ?? $view;

            return $this->render('learning_path/quiz_result.html.twig', [
                'path' => $path,
                'view' => $view,
                'attempt' => $attempt,
                'next' => $progress->after($view),
                'progress' => $progress,
                'explanations' => $this->explanations($attempt, $questions),
            ]);
        }

        $question = $this->questionAt($attempt, $index, $questions);
        if (null === $question) {
            // Deleted from the library since the draw: counted wrong and stepped over.
            $follower->answer($attempt, $index, null, []);

            return $this->redirectToRoute('app_learning_path_attempt', $here);
        }

        return $this->render('learning_path/quiz_question.html.twig', [
            'path' => $path,
            'view' => $view,
            'attempt' => $attempt,
            'index' => $index,
            ...QuizQuestionTakeView::of($question, $follower->variablesFor($attempt, $question)),
        ]);
    }

    private function questionAt(LearningPathQuizAttempt $attempt, int $index, QuizQuestionRepository $questions): ?QuizQuestion
    {
        $drawn = $attempt->getQuestions()[$index] ?? null;
        if (null === $drawn) {
            return null;
        }

        $question = $questions->find($drawn['id']);

        // Still a question of the step's own quiz: an id is only ever trusted for what it was drawn as.
        return $question instanceof QuizQuestion && $question->getQuizTemplate() === $attempt->getStep()->getQuizTemplate() ? $question : null;
    }

    /**
     * The explanation of each question asked, by question id - read from the library as it stands,
     * since the correction is shown the moment the attempt ends.
     *
     * @return array<int, string>
     */
    private function explanations(LearningPathQuizAttempt $attempt, QuizQuestionRepository $questions): array
    {
        $ids = array_column($attempt->getQuestions(), 'id');
        $explanations = [];

        foreach ([] === $ids ? [] : $questions->findBy(['id' => $ids]) as $question) {
            if ($question->getQuizTemplate() === $attempt->getStep()->getQuizTemplate() && null !== $question->getExplanation() && '' !== trim($question->getExplanation())) {
                $explanations[(int) $question->getId()] = $question->getExplanation();
            }
        }

        return $explanations;
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
