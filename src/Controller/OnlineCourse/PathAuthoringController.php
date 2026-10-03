<?php

declare(strict_types=1);

namespace App\Controller\OnlineCourse;

use App\Attribute\RequiresFeature;
use App\Entity\LearningPath;
use App\Entity\LearningPathStep;
use App\Entity\User;
use App\Enum\Feature;
use App\Form\LearningPathType;
use App\Repository\LearningPathRepository;
use App\Repository\OnlineCourseRepository;
use App\Repository\QuizTemplateRepository;
use App\Security\Voter\LearningPathVoter;
use App\Service\JsonRequestPayload;
use App\Service\LearningPath\LearningPathRefused;
use App\Service\LearningPath\LearningPathWriter;
use App\Service\PostValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Cours en ligne › Mes parcours », the author's side (design/validated/cours-en-ligne.md, §10):
 * the list of one's own paths, and composing one - its card and its ordered steps, a course or a
 * validation quiz at a time.
 *
 * Composing a path is the author's tool and so belongs to `online_courses`, like the courses it
 * lines up; *following* one is `learning_paths` (App\Controller\LearningPath\PathController). A
 * path is its author's alone, administrators included (App\Security\Voter\LearningPathVoter).
 */
#[RequiresFeature(Feature::OnlineCourses)]
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'), statusCode: 404)]
class PathAuthoringController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'learning_path_authoring';

    public function __construct(
        private readonly LearningPathRepository $paths,
        private readonly LearningPathWriter $writer,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/tools/online-courses/paths', name: 'app_online_courses_paths', methods: ['GET'])]
    public function index(): Response
    {
        $paths = $this->paths->findForOwner($this->currentUser());

        return $this->render('online_course/authoring/paths.html.twig', [
            'paths' => $paths,
            'followers' => array_combine(
                array_map(static fn (LearningPath $path): int => (int) $path->getId(), $paths),
                array_map(fn (LearningPath $path): int => $this->writer->followerCount($path), $paths),
            ),
        ]);
    }

    #[Route(path: '/tools/online-courses/paths/new', name: 'app_online_courses_path_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $path = new LearningPath($this->currentUser(), '');
        $form = $this->createForm(LearningPathType::class, $path);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->writer->describe($path, $path->getDescription());
            $this->entityManager->persist($path);
            $this->entityManager->flush();
            $this->addFlash('success', 'learningPathCreatedFlashMessage');

            return $this->redirectToRoute('app_online_courses_path_edit', ['id' => $path->getId()]);
        }

        return $this->render('online_course/authoring/path_new.html.twig', ['form' => $form]);
    }

    #[Route(path: '/tools/online-courses/paths/{id}', name: 'app_online_courses_path_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, OnlineCourseRepository $courses, QuizTemplateRepository $quizzes): Response
    {
        $path = $this->findPath($id);
        $form = $this->createForm(LearningPathType::class, $path);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->writer->describe($path, $path->getDescription());
            $this->entityManager->flush();
            $this->addFlash('success', 'learningPathSavedFlashMessage');

            return $this->redirectToRoute('app_online_courses_path_edit', ['id' => $path->getId()]);
        }

        if ($form->isSubmitted()) {
            $this->entityManager->refresh($path);
        }

        $used = array_filter(array_map(static fn (LearningPathStep $step): ?int => $step->getCourse()?->getId(), $path->orderedSteps()));

        return $this->render('online_course/authoring/path_edit.html.twig', [
            'form' => $form,
            'path' => $path,
            'steps' => $path->orderedSteps(),
            'publishRefusals' => $this->writer->publishRefusals($path),
            'followerCount' => $this->writer->followerCount($path),
            // A course goes into a path once: the picker only offers the ones not there yet.
            'courses' => array_values(array_filter($courses->findForOwner($path->getOwner()), static fn ($course): bool => !\in_array($course->getId(), $used, true))),
            'quizzes' => $quizzes->findPickable($path->getOwner()),
        ]);
    }

    #[Route(path: '/tools/online-courses/paths/{id}/steps', name: 'app_online_courses_path_step_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addStep(int $id, Request $request, OnlineCourseRepository $courses, QuizTemplateRepository $quizzes): Response
    {
        $path = $this->findPath($id);
        $this->assertCsrf($request);

        try {
            if ('quiz' === PostValue::string($request, 'type')) {
                $quiz = $quizzes->find(PostValue::int($request, 'quiz'));
                if (null === $quiz) {
                    throw new LearningPathRefused('learningPathChooseQuizMessage');
                }
                $this->writer->addQuiz($path, $quiz, PostValue::int($request, 'passPercent', LearningPathStep::DEFAULT_PASS_PERCENT), PostValue::nullableInt($request, 'questionCount'));
            } else {
                $course = $courses->find(PostValue::int($request, 'course'));
                if (null === $course) {
                    throw new LearningPathRefused('learningPathChooseCourseMessage');
                }
                $this->writer->addCourse($path, $course);
            }

            $this->entityManager->flush();
            $this->addFlash('success', 'learningPathStepAddedFlashMessage');
        } catch (LearningPathRefused $refused) {
            $this->addFlash('error', $this->translate($refused));
        }

        return $this->redirectToRoute('app_online_courses_path_edit', ['id' => $path->getId()]);
    }

    #[Route(path: '/tools/online-courses/paths/{id}/steps/{stepId}', name: 'app_online_courses_path_step_update', requirements: ['id' => '\d+', 'stepId' => '\d+'], methods: ['POST'])]
    public function updateStep(int $id, int $stepId, Request $request): Response
    {
        $path = $this->findPath($id);
        $this->assertCsrf($request);

        try {
            $this->writer->configureQuiz($this->findStep($path, $stepId), PostValue::int($request, 'passPercent', LearningPathStep::DEFAULT_PASS_PERCENT), PostValue::nullableInt($request, 'questionCount'));
            $this->entityManager->flush();
            $this->addFlash('success', 'learningPathStepSavedFlashMessage');
        } catch (LearningPathRefused $refused) {
            $this->addFlash('error', $this->translate($refused));
        }

        return $this->redirectToRoute('app_online_courses_path_edit', ['id' => $path->getId()]);
    }

    #[Route(path: '/tools/online-courses/paths/{id}/steps/{stepId}/remove', name: 'app_online_courses_path_step_remove', requirements: ['id' => '\d+', 'stepId' => '\d+'], methods: ['POST'])]
    public function removeStep(int $id, int $stepId, Request $request): Response
    {
        $path = $this->findPath($id);
        $this->assertCsrf($request);

        $this->writer->removeStep($this->findStep($path, $stepId));
        $this->entityManager->flush();
        $this->addFlash('success', 'learningPathStepRemovedFlashMessage');

        return $this->redirectToRoute('app_online_courses_path_edit', ['id' => $path->getId()]);
    }

    /** The order of the steps, sent whole by the drag handle - a JSON list of ids, the token in a header. */
    #[Route(path: '/tools/online-courses/paths/{id}/steps/order', name: 'app_online_courses_path_step_order', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function order(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $path = $this->findPath($id);
        $this->writer->reorder($path, JsonRequestPayload::fromRequest($request)->ids('ids'));
        $this->entityManager->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route(path: '/tools/online-courses/paths/{id}/publish', name: 'app_online_courses_path_publish', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function publish(int $id, Request $request): Response
    {
        $path = $this->findPath($id);
        $this->assertCsrf($request);

        try {
            $this->writer->publish($path);
            $this->entityManager->flush();
            $this->addFlash('success', 'learningPathPublishedFlashMessage');
        } catch (LearningPathRefused $refused) {
            $this->addFlash('error', $this->translate($refused));
        }

        return $this->redirectToRoute('app_online_courses_path_edit', ['id' => $path->getId()]);
    }

    #[Route(path: '/tools/online-courses/paths/{id}/unpublish', name: 'app_online_courses_path_unpublish', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function unpublish(int $id, Request $request): Response
    {
        $path = $this->findPath($id);
        $this->assertCsrf($request);

        $this->writer->unpublish($path);
        $this->entityManager->flush();
        $this->addFlash('success', 'learningPathUnpublishedFlashMessage');

        return $this->redirectToRoute('app_online_courses_path_edit', ['id' => $path->getId()]);
    }

    #[Route(path: '/tools/online-courses/paths/{id}/delete', name: 'app_online_courses_path_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $path = $this->findPath($id);
        $this->assertCsrf($request);

        if (!$this->isGranted(LearningPathVoter::DELETE, $path)) {
            $this->addFlash('error', 'learningPathDeletePublishedFlashMessage');

            return $this->redirectToRoute('app_online_courses_path_edit', ['id' => $path->getId()]);
        }

        $this->writer->delete($path);
        $this->entityManager->flush();
        $this->addFlash('success', 'learningPathDeletedFlashMessage');

        return $this->redirectToRoute('app_online_courses_paths');
    }

    private function findPath(int $id, string $attribute = LearningPathVoter::EDIT): LearningPath
    {
        $path = $this->paths->find($id);
        if (null === $path || !$this->isGranted($attribute, $path)) {
            throw $this->createNotFoundException();
        }

        return $path;
    }

    private function findStep(LearningPath $path, int $stepId): LearningPathStep
    {
        foreach ($path->getSteps() as $step) {
            if ($step->getId() === $stepId) {
                return $step;
            }
        }

        throw $this->createNotFoundException();
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /** A refusal is put in a flash already translated: its parameters would not survive the redirect. */
    private function translate(LearningPathRefused $refused): string
    {
        return $this->translator->trans($refused->getMessage(), $refused->parameters);
    }
}
