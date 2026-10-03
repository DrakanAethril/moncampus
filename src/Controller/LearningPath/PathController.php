<?php

declare(strict_types=1);

namespace App\Controller\LearningPath;

use App\Attribute\RequiresFeature;
use App\Entity\OnlineCourseMaterial;
use App\Enum\Feature;
use App\Repository\LearningPathEnrollmentRepository;
use App\Repository\LearningPathRepository;
use App\Security\Voter\LearningPathVoter;
use App\Service\LearningPath\LearningPathBoard;
use App\Service\LearningPath\LearningPathFollower;
use App\Service\LearningPath\LearningPathStepView;
use App\Service\OnlineCourse\OnlineCourseContentOrigin;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Following a learning path (design/validated/cours-en-ligne.md, §10): « Mes parcours », a path's
 * plan, and its steps.
 *
 * **Not one of these routes is read without an account.** They sit under `/paths`, outside the
 * `^/courses/` opening, so the catch-all of config/packages/security.yaml asks for a signed-in user
 * before anything here runs - the plan and the title included. `learning_paths` then decides
 * whether the establishment runs them at all: an account it is not lit for is answered 404.
 *
 * A step is shown here, in the application's shell, and by this route alone - which is what lets
 * one place write « this person opened this step » (App\Service\LearningPath\LearningPathFollower).
 * A course reserved for learning paths is read nowhere else.
 */
#[RequiresFeature(Feature::LearningPaths)]
class PathController extends AbstractController
{
    use PathFollowingTrait;

    public const string CSRF_TOKEN_ID = 'learning_path';

    private const string SEGMENT = '[a-z0-9]+(?:-[a-z0-9]+)*';

    #[Route(path: '/paths', name: 'app_learning_paths', methods: ['GET'])]
    public function index(LearningPathEnrollmentRepository $enrollments, LearningPathBoard $board): Response
    {
        $rows = [];
        foreach ($enrollments->findForUser($this->currentUser()) as $enrollment) {
            // A path its author took offline is no longer followed; what was done on it is kept,
            // and comes back with the path.
            if (!$this->isGranted(LearningPathVoter::FOLLOW, $enrollment->getPath())) {
                continue;
            }
            $rows[] = ['path' => $enrollment->getPath(), 'progress' => $board->progress($enrollment->getPath(), $enrollment)];
        }

        return $this->render('learning_path/index.html.twig', ['rows' => $rows]);
    }

    #[Route(path: '/paths/{id}', name: 'app_learning_path_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, LearningPathRepository $paths, LearningPathEnrollmentRepository $enrollments, LearningPathBoard $board): Response
    {
        $path = $this->findPath($id, $paths);
        $enrollment = $enrollments->findOneFor($path, $this->currentUser());

        return $this->render('learning_path/show.html.twig', [
            'path' => $path,
            'enrollment' => $enrollment,
            'progress' => $board->progress($path, $enrollment),
        ]);
    }

    /** « Commencer le parcours »: the click that writes the start date. */
    #[Route(path: '/paths/{id}/start', name: 'app_learning_path_start', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function start(int $id, Request $request, LearningPathRepository $paths, LearningPathFollower $follower, LearningPathBoard $board): Response
    {
        $path = $this->findPath($id, $paths);
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $enrollment = $follower->start($path, $this->currentUser());
        $next = $board->progress($path, $enrollment)->next();

        return null === $next
            ? $this->redirectToRoute('app_learning_path_show', ['id' => $path->getId()])
            : $this->redirectToRoute('app_learning_path_step', ['id' => $path->getId(), 'number' => $next->number]);
    }

    #[Route(path: '/paths/{id}/steps/{number}/{material}', name: 'app_learning_path_step', requirements: ['id' => '\d+', 'number' => '\d+', 'material' => self::SEGMENT], defaults: ['material' => null], methods: ['GET'])]
    public function step(int $id, int $number, ?string $material, Request $request, LearningPathRepository $paths, LearningPathEnrollmentRepository $enrollments, LearningPathBoard $board, LearningPathFollower $follower, OnlineCourseContentOrigin $origin, LoggerInterface $logger): Response
    {
        $path = $this->findPath($id, $paths);
        $found = $this->stepOrRedirect($path, $number, $enrollments, $board);
        if ($found instanceof RedirectResponse) {
            return $found;
        }

        [$enrollment, , $view] = $found;
        $follower->open($enrollment, $view->step);
        // Read again: opening a course is what ticks it, and may be what completes the path.
        $progress = $board->progress($path, $enrollment);
        $view = $progress->viewOf($number) ?? $view;

        if ($view->step->isQuiz()) {
            return $this->render('learning_path/step_quiz.html.twig', [
                'path' => $path,
                'progress' => $progress,
                'view' => $view,
                'next' => $progress->after($view),
            ]);
        }

        $course = $view->step->getCourse() ?? throw $this->createNotFoundException();
        $current = $this->materialOf($view, $material);
        $live = $current?->getLive();

        return $this->render('learning_path/step_course.html.twig', [
            'path' => $path,
            'progress' => $progress,
            'view' => $view,
            'next' => $progress->after($view),
            'course' => $course,
            'current' => $current,
            'currentUrl' => null === $live ? null : $origin->url($live),
            'frameAllowed' => null === $current || $this->frameAllowed($current, $request, $origin, $logger),
        ]);
    }

    /** The step's material alone in the window - the full page, inside a path. */
    #[Route(path: '/paths/{id}/steps/{number}/{material}/play', name: 'app_learning_path_play', requirements: ['id' => '\d+', 'number' => '\d+', 'material' => self::SEGMENT], methods: ['GET'])]
    public function play(int $id, int $number, string $material, Request $request, LearningPathRepository $paths, LearningPathEnrollmentRepository $enrollments, LearningPathBoard $board, OnlineCourseContentOrigin $origin, LoggerInterface $logger): Response
    {
        $path = $this->findPath($id, $paths);
        $found = $this->stepOrRedirect($path, $number, $enrollments, $board);
        if ($found instanceof RedirectResponse) {
            return $found;
        }

        [, $progress, $view] = $found;
        $course = $view->step->getCourse() ?? throw $this->createNotFoundException();
        $current = $this->materialOf($view, $material);
        $live = $current?->getLive();
        if (null === $current || null === $live || !($current->getKind()->isBundle() || $current->getKind()->isDocument())) {
            throw $this->createNotFoundException();
        }

        $next = $progress->after($view);

        return $this->render('online_course/public/play.html.twig', [
            'course' => $course,
            'material' => $current,
            'url' => $origin->url($live),
            'frameAllowed' => $this->frameAllowed($current, $request, $origin, $logger),
            'backUrl' => $this->generateUrl('app_learning_path_step', ['id' => $path->getId(), 'number' => $number, 'material' => $current->getSlug()]),
            'nextUrl' => null === $next ? null : $this->generateUrl('app_learning_path_step', ['id' => $path->getId(), 'number' => $next->number]),
        ]);
    }

    private function materialOf(LearningPathStepView $view, ?string $slug): ?OnlineCourseMaterial
    {
        $course = $view->step->getCourse();
        if (null === $course) {
            return null;
        }

        if (null === $slug) {
            return $course->getMaterials()->first() ?: null;
        }

        return $course->findMaterialBySlug($slug) ?? throw $this->createNotFoundException();
    }

    /** The same guard as the public course page: no frame unless the content origin is another host. */
    private function frameAllowed(OnlineCourseMaterial $material, Request $request, OnlineCourseContentOrigin $origin, LoggerInterface $logger): bool
    {
        if (!$material->getKind()->isBundle() || $origin->isIsolatedFrom($request->getHost())) {
            return true;
        }

        $logger->error('An interactive course was not drawn: the content origin is the application\'s own host, so its scripts would not be isolated.', [
            'material' => $material->getId(),
            'host' => $request->getHost(),
        ]);

        return false;
    }
}
