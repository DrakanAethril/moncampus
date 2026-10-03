<?php

declare(strict_types=1);

namespace App\Controller\OnlineCourse;

use App\Entity\OnlineCourse;
use App\Entity\OnlineCoursePage;
use App\Repository\OnlineCourseRepository;
use App\Security\Voter\OnlineCourseVoter;
use App\Service\OnlineCourse\OnlineCoursePageHandles;
use App\Service\OnlineCourse\OnlineCourseTestRun;
use App\Service\OnlineCourse\OnlineCourseTestRunner;
use App\Service\PostValue;
use App\Service\QuizQuestionTakeView;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A course's test, `/courses/{handle}/{slug}/test`: the quiz its author linked to it, reached from
 * « Test » on its card. Every question of the quiz, one at a time, questions and answers in a new
 * random order at every go, as many goes as wanted - and nothing recorded
 * (App\Service\OnlineCourse\OnlineCourseTestRunner).
 *
 * **The end shows the score and nothing else**: no question marked right or wrong, no correction.
 * The point is to send the reader back to the course when the score is not good enough, not to
 * let them learn the answers or chase the questions they missed. Above the author's threshold the
 * screen says « Bravo », below it suggests reading the course again.
 *
 * Read by whoever may read the course - App\Security\Voter\OnlineCourseVoter's VIEW, a visitor
 * without an account included - and 404 for a course with no test. No App\Attribute\RequiresFeature,
 * for the reason App\Controller\OnlineCourse\PublicPageController gives.
 *
 * The routes outrank the material's (`/courses/{handle}/{slug}/{material}`, priority 1): `test` is
 * not a material's slug - those are the natures' own values - but the order must not depend on it.
 */
class PublicCourseTestController extends AbstractController
{
    use PublicCourseTrait;

    public const string CSRF_TOKEN_ID = 'online_course_test';

    private const string SEGMENT = '[a-z0-9]+(?:-[a-z0-9]+)*';

    public function __construct(
        private readonly OnlineCoursePageHandles $handles,
        private readonly OnlineCourseRepository $courses,
        private readonly OnlineCourseTestRunner $runner,
    ) {
    }

    /**
     * The question to answer next. A run already finished - or none at all - starts a new one: this
     * is the address « Test » and « Recommencer » both point at.
     */
    #[Route(path: '/courses/{handle}/{slug}/test', name: 'app_public_courses_test', requirements: ['handle' => self::SEGMENT, 'slug' => self::SEGMENT], methods: ['GET'], priority: 1)]
    public function show(string $handle, string $slug, Request $request): Response
    {
        $found = $this->find($handle, $slug, $request);
        if ($found instanceof RedirectResponse) {
            return $found;
        }

        [$page, $course] = $found;
        $run = $this->runner->current($course);
        if (null === $run || $run->isFinished()) {
            $run = $this->runner->start($course);
        }

        $index = $run->nextIndex();
        if (null === $index) {
            // Nothing could be drawn - every question waits for its image.
            $this->runner->close($course, $run);

            return $this->redirectToRoute('app_public_courses_test_result', ['handle' => $page->getHandle(), 'slug' => $course->getSlug()]);
        }

        $question = $this->runner->question($course, $run, $index);
        if (null === $question) {
            // Deleted from the library since the draw: counted wrong and stepped over.
            $this->runner->answer($course, $run, $index, null, []);

            return $this->redirectToRoute($run->isFinished() ? 'app_public_courses_test_result' : 'app_public_courses_test', ['handle' => $page->getHandle(), 'slug' => $course->getSlug()]);
        }

        return $this->render('online_course/public/test_question.html.twig', [
            'page' => $page,
            'course' => $course,
            'run' => $run,
            'index' => $index,
            ...QuizQuestionTakeView::of($question, $this->runner->variablesFor($run, $question), shuffleAnswers: true),
        ]);
    }

    #[Route(path: '/courses/{handle}/{slug}/test', name: 'app_public_courses_test_answer', requirements: ['handle' => self::SEGMENT, 'slug' => self::SEGMENT], methods: ['POST'], priority: 1)]
    public function answer(string $handle, string $slug, Request $request): Response
    {
        $found = $this->find($handle, $slug, $request);
        if ($found instanceof RedirectResponse) {
            return $found;
        }

        [$page, $course] = $found;
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // The index is posted with the answer and checked against the run's own: a form left open
        // in another tab answers the question it was drawn for, or nothing.
        $run = $this->runner->current($course);
        $posted = PostValue::string($request, 'index');
        $index = ctype_digit($posted) ? (int) $posted : -1;
        if (null !== $run && !$run->isFinished() && $index === $run->nextIndex()) {
            $this->runner->answer($course, $run, $index, $this->runner->question($course, $run, $index), $request->request->all());
        }

        $here = ['handle' => $page->getHandle(), 'slug' => $course->getSlug()];

        return $run instanceof OnlineCourseTestRun && $run->isFinished()
            ? $this->redirectToRoute('app_public_courses_test_result', $here)
            : $this->redirectToRoute('app_public_courses_test', $here);
    }

    /** The score and the correction of the run just finished. */
    #[Route(path: '/courses/{handle}/{slug}/test/result', name: 'app_public_courses_test_result', requirements: ['handle' => self::SEGMENT, 'slug' => self::SEGMENT], methods: ['GET'], priority: 1)]
    public function result(string $handle, string $slug, Request $request): Response
    {
        $found = $this->find($handle, $slug, $request);
        if ($found instanceof RedirectResponse) {
            return $found;
        }

        [$page, $course] = $found;
        $run = $this->runner->current($course);
        if (null === $run || !$run->isFinished()) {
            return $this->redirectToRoute('app_public_courses_test', ['handle' => $page->getHandle(), 'slug' => $course->getSlug()]);
        }

        return $this->render('online_course/public/test_result.html.twig', [
            'page' => $page,
            'course' => $course,
            'run' => $run,
            'passed' => $run->scorePercent() >= $course->getTestPassPercent(),
        ]);
    }

    /**
     * @return array{OnlineCoursePage, OnlineCourse}|RedirectResponse
     */
    private function find(string $handle, string $slug, Request $request): array|RedirectResponse
    {
        $page = $this->pageOrRedirect($handle, $request, $this->handles);
        if ($page instanceof RedirectResponse) {
            return $page;
        }

        $course = $this->courses->findOneByOwnerAndSlug($page->getOwner(), $slug);
        if (null === $course || !$course->hasTest() || !$this->isGranted(OnlineCourseVoter::VIEW, $course)) {
            throw $this->createNotFoundException();
        }

        return [$page, $course];
    }
}
