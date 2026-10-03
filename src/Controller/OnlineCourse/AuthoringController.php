<?php

declare(strict_types=1);

namespace App\Controller\OnlineCourse;

use App\Attribute\RequiresFeature;
use App\Entity\FileLibraryNode;
use App\Entity\OnlineCourse;
use App\Entity\QuizFolder;
use App\Entity\QuizTemplate;
use App\Enum\Feature;
use App\Enum\OnlineCourseMaterialKind;
use App\Enum\OnlineCourseStatus;
use App\Form\OnlineCourseType;
use App\Repository\LearningPathRepository;
use App\Repository\OnlineCoursePageRepository;
use App\Repository\OnlineCourseRepository;
use App\Repository\OnlineCourseTagRepository;
use App\Repository\QuizFolderRepository;
use App\Security\Voter\OnlineCourseVoter;
use App\Service\FormValue;
use App\Service\LibraryPickerTree;
use App\Service\OnlineCourse\OnlineCourseImageStore;
use App\Service\OnlineCourse\OnlineCourseMaterialRefused;
use App\Service\OnlineCourse\OnlineCoursePublicationRefused;
use App\Service\OnlineCourse\OnlineCourseQuizRefused;
use App\Service\OnlineCourse\OnlineCourseTagResolver;
use App\Service\OnlineCourse\OnlineCourseWriter;
use App\Service\QueryValue;
use App\Service\StagedUpload;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Outils › Cours en ligne », the author's side (design/validated/cours-en-ligne.md, §8): the list
 * of one's own courses, a course's card, and the three gestures on it - put online, take offline,
 * delete.
 *
 * A teacher's tool: whoever is neither teaching nor staff is answered 404 even where
 * `online_courses` has been lit for them. And a course is its author's alone, administrators
 * included - with the one exception the Voter states, UNPUBLISH, which is how an administrator
 * takes somebody else's course off a public page without being able to rewrite it.
 */
#[RequiresFeature(Feature::OnlineCourses)]
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'), statusCode: 404)]
class AuthoringController extends AbstractController
{
    use OnlineCourseAuthoringTrait;

    public const string CSRF_TOKEN_ID = 'online_course';

    #[Route(path: '/tools/online-courses', name: 'app_online_courses', methods: ['GET'])]
    public function index(Request $request, OnlineCourseRepository $courses, OnlineCoursePageRepository $pages): Response
    {
        $user = $this->currentUser();
        $all = $courses->findForOwner($user);

        // Both filters only ever narrow, and both are read as what a filter bar submits: an empty
        // value is « tous », never an error (App\Service\QueryValue).
        $status = OnlineCourseStatus::tryFrom(QueryValue::trimmed($request, 'status'));
        $tag = QueryValue::trimmed($request, 'tag');

        $shown = array_values(array_filter($all, static fn (OnlineCourse $course): bool => (null === $status || $course->getStatus() === $status)
            && ('' === $tag || $course->hasTag($tag))));

        $tags = [];
        foreach ($all as $course) {
            foreach ($course->getTags() as $courseTag) {
                $tags[$courseTag->getNormalizedLabel()] = $courseTag->getLabel();
            }
        }
        asort($tags);

        return $this->render('online_course/authoring/index.html.twig', [
            'courses' => $shown,
            'total' => \count($all),
            'page' => $pages->findOneByOwner($user),
            'statusFilter' => $status,
            'tagFilter' => $tag,
            'tags' => $tags,
            'statuses' => OnlineCourseStatus::cases(),
        ]);
    }

    #[Route(path: '/tools/online-courses/new', name: 'app_online_courses_new', methods: ['GET', 'POST'])]
    public function new(Request $request, OnlineCourseWriter $writer, EntityManagerInterface $entityManager, OnlineCourseImageStore $images, TranslatorInterface $translator, QuizFolderRepository $folders, LibraryPickerTree $pickerTree): Response
    {
        $user = $this->currentUser();
        $course = new OnlineCourse($user, '', '');
        $form = $this->createForm(OnlineCourseType::class, $course);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $writer->describe($course, $course->getDescription());
            $writer->tag($course, OnlineCourseTagResolver::labelsOf(FormValue::string($form, 'tags')));
            $this->applyQuiz($form, $course, $writer, $translator);
            $entityManager->persist($course);
            $entityManager->flush();
            // A second flush: the picture is filed under the course's id, which the first one gave.
            $this->applyImage($form, $course, $images, $translator);
            $entityManager->flush();

            $this->addFlash('success', 'onlineCourseCreatedFlashMessage');

            return $this->redirectToRoute('app_online_courses_edit', ['id' => $course->getId()]);
        }

        return $this->render('online_course/authoring/new.html.twig', [
            'form' => $form,
            'course' => $course,
            'quizPickerTree' => $this->quizPickerTree($form, $folders, $pickerTree),
        ]);
    }

    #[Route(path: '/tools/online-courses/{id}', name: 'app_online_courses_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, OnlineCourseRepository $courses, OnlineCoursePageRepository $pages, OnlineCourseTagRepository $tags, OnlineCourseWriter $writer, EntityManagerInterface $entityManager, LearningPathRepository $paths, OnlineCourseImageStore $images, TranslatorInterface $translator, QuizFolderRepository $folders, LibraryPickerTree $pickerTree): Response
    {
        $course = $this->findCourse($id, $courses);
        $form = $this->createForm(OnlineCourseType::class, $course);
        $form->get('tags')->setData(OnlineCourseTagResolver::fieldValue($course));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $writer->describe($course, $course->getDescription());
            $writer->tag($course, OnlineCourseTagResolver::labelsOf(FormValue::string($form, 'tags')));
            $this->applyQuiz($form, $course, $writer, $translator);
            $this->applyImage($form, $course, $images, $translator);
            $entityManager->flush();
            $tags->deleteUnusedForOwner($course->getOwner());

            $this->addFlash('success', 'onlineCourseSavedFlashMessage');

            return $this->redirectToRoute('app_online_courses_edit', ['id' => $course->getId()]);
        }

        if ($form->isSubmitted()) {
            // The unit of work holds what the invalid form wrote into the entity; the screen is
            // redrawn from it, but nothing of it must be flushed by a later gesture.
            $entityManager->refresh($course);
        }

        return $this->render('online_course/authoring/edit.html.twig', [
            'form' => $form,
            'course' => $course,
            'page' => $pages->findOneByOwner($course->getOwner()),
            'publishRefusals' => $writer->publishRefusals($course),
            'kinds' => self::offeredKinds(),
            'paths' => $paths->findUsingCourse($course),
            'quizPickerTree' => $this->quizPickerTree($form, $folders, $pickerTree),
        ]);
    }

    #[Route(path: '/tools/online-courses/{id}/publish', name: 'app_online_courses_publish', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function publish(int $id, Request $request, OnlineCourseRepository $courses, OnlineCourseWriter $writer, EntityManagerInterface $entityManager): Response
    {
        $this->assertCsrf($request, self::CSRF_TOKEN_ID);
        $course = $this->findCourse($id, $courses, OnlineCourseVoter::PUBLISH);

        // Public by default; « Réservé aux parcours » keeps it off the author's page, read only from
        // an opened step of a path. The same gesture moves a published course between the two.
        $status = 'path_only' === $request->request->getString('visibility') ? OnlineCourseStatus::PathOnly : OnlineCourseStatus::PublicCourse;

        try {
            $wasPublished = $course->isPublished();
            $writer->publish($course, $status);
            $entityManager->flush();
            $this->addFlash('success', $wasPublished ? 'onlineCourseVisibilityChangedFlashMessage' : 'onlineCoursePublishedFlashMessage');
        } catch (OnlineCoursePublicationRefused $refused) {
            foreach ($refused->reasons as $reason) {
                $this->addFlash('error', $reason);
            }
        }

        return $this->redirectToRoute('app_online_courses_edit', ['id' => $course->getId()]);
    }

    #[Route(path: '/tools/online-courses/{id}/unpublish', name: 'app_online_courses_unpublish', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function unpublish(int $id, Request $request, OnlineCourseRepository $courses, OnlineCoursePageRepository $pages, OnlineCourseWriter $writer, EntityManagerInterface $entityManager): Response
    {
        $this->assertCsrf($request, self::CSRF_TOKEN_ID);
        $course = $this->findCourse($id, $courses, OnlineCourseVoter::UNPUBLISH);

        $writer->unpublish($course);
        $entityManager->flush();
        $this->addFlash('success', 'onlineCourseUnpublishedFlashMessage');

        // An administrator taking somebody else's course offline has no card to go back to: the
        // course is not theirs to edit. They land on the page it has just left.
        if (!$course->isOwnedBy($this->currentUser())) {
            $page = $pages->findOneByOwner($course->getOwner());

            return null === $page
                ? $this->redirectToRoute('app_home')
                : $this->redirectToRoute('app_public_courses_page', ['handle' => $page->getHandle()]);
        }

        return $this->redirectToRoute('app_online_courses_edit', ['id' => $course->getId()]);
    }

    #[Route(path: '/tools/online-courses/{id}/delete', name: 'app_online_courses_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request, OnlineCourseRepository $courses, OnlineCourseWriter $writer, LearningPathRepository $paths): Response
    {
        $this->assertCsrf($request, self::CSRF_TOKEN_ID);
        $course = $this->findCourse($id, $courses);

        // A course a path lines up is taken out of the path first: deleting it would take the step
        // - and what its followers did on it - along silently.
        if ([] !== $paths->findUsingCourse($course)) {
            $this->addFlash('error', 'onlineCourseDeleteUsedByPathFlashMessage');

            return $this->redirectToRoute('app_online_courses_edit', ['id' => $course->getId()]);
        }

        if (!$this->isGranted(OnlineCourseVoter::DELETE, $course)) {
            $this->addFlash('error', 'onlineCourseDeletePublishedFlashMessage');

            return $this->redirectToRoute('app_online_courses_edit', ['id' => $course->getId()]);
        }

        $writer->delete($course);
        $this->addFlash('success', 'onlineCourseDeletedFlashMessage');

        return $this->redirectToRoute('app_online_courses');
    }

    /** The suggestion list of the tag field: the author's own vocabulary, with its usage. */
    #[Route(path: '/tools/online-courses/tags', name: 'app_online_courses_tags', methods: ['GET'])]
    public function tags(Request $request, OnlineCourseTagRepository $tags): JsonResponse
    {
        return $this->json($tags->searchForOwner($this->currentUser(), QueryValue::trimmed($request, 'q')));
    }

    /**
     * A new picture wins over « Retirer la vignette » ticked in the same submission. A refusal is
     * a flash, not an invalid form: the picker has already checked the file against the same
     * policy, so only a library file the picker could not sniff gets here - and the rest of the
     * card is still worth saving.
     *
     * @param FormInterface<mixed> $form
     */
    private function applyImage(FormInterface $form, OnlineCourse $course, OnlineCourseImageStore $images, TranslatorInterface $translator): void
    {
        $image = $form->get('image')->getData();

        if ($image instanceof StagedUpload || $image instanceof FileLibraryNode) {
            try {
                $images->set($course, $image);
            } catch (OnlineCourseMaterialRefused $refused) {
                $this->addFlash('error', $translator->trans($refused->getMessage(), $refused->parameters));
            }
        } elseif (true === $form->get('removeImage')->getData()) {
            $images->remove($course);
        }
    }

    /**
     * The test quiz chosen on the card - or none. A refusal is a flash, like the picture's: the
     * field's choices already hold the writer's rule, so only a quiz emptied between the display and
     * the submit gets here, and the rest of the card is still worth saving.
     *
     * @param FormInterface<mixed> $form
     */
    private function applyQuiz(FormInterface $form, OnlineCourse $course, OnlineCourseWriter $writer, TranslatorInterface $translator): void
    {
        $quiz = $form->get('quiz')->getData();

        try {
            $writer->linkQuiz($course, $quiz instanceof QuizTemplate ? $quiz : null);
        } catch (OnlineCourseQuizRefused $refused) {
            $this->addFlash('error', $translator->trans($refused->getMessage(), $refused->parameters));
        }
    }

    /**
     * The quiz library as the picker modal walks it - the field's own choices in the author's own
     * folders, so the modal cannot offer what the field would refuse.
     *
     * @param FormInterface<mixed> $form
     *
     * @return array{folders: list<array<string, mixed>>, items: list<array<string, mixed>>}
     */
    private function quizPickerTree(FormInterface $form, QuizFolderRepository $folders, LibraryPickerTree $pickerTree): array
    {
        /** @var list<QuizTemplate> $quizzes */
        $quizzes = $form->get('quiz')->getConfig()->getOption('choices');

        return $pickerTree->build(
            array_map(
                static fn (QuizFolder $folder): array => [
                    'id' => (int) $folder->getId(),
                    'parentId' => $folder->getParent()?->getId(),
                    'name' => $folder->getName(),
                ],
                $folders->findAllFor($this->currentUser()),
            ),
            array_map(
                static fn (QuizTemplate $quiz): array => [
                    'id' => (int) $quiz->getId(),
                    'folderId' => $quiz->getFolder()?->getId(),
                    'label' => $quiz->getName() ?? '',
                    'count' => $quiz->getQuestions()->count(),
                ],
                $quizzes,
            ),
        );
    }

    /**
     * The natures « Ajouter un support » offers, in the order a course page shows them.
     *
     * @return list<OnlineCourseMaterialKind>
     */
    public static function offeredKinds(): array
    {
        return [OnlineCourseMaterialKind::Interactive, OnlineCourseMaterialKind::Pdf, OnlineCourseMaterialKind::Summary, OnlineCourseMaterialKind::Video];
    }
}
