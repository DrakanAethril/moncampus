<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\QuizFolder;
use App\Repository\QuizFolderRepository;
use App\Repository\QuizTemplateRepository;
use App\Security\Voter\QuizFolderVoter;
use App\Security\Voter\QuizTemplateVoter;
use App\Service\PostValue;
use App\Service\QuizFolderManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Everything that changes the classement of a quiz library: new folder, rename, move, delete, and
 * the one drag this feature exists for - a quiz dropped onto a folder of the rail.
 *
 * A controller of its own rather than four more actions in the 1 300-line
 * App\Controller\QuizLibraryController, per the repository's rule: when you touch a fat controller,
 * extract rather than extend.
 *
 * **Every POST redirects**, which is Turbo's rule here - the two exceptions are the moves, which are
 * drags answering JSON to a fetch. The rules themselves are App\Service\QuizFolderManager's.
 */
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class QuizFolderController extends AbstractController
{
    use QuizLibraryFolderTrait;

    private const string CSRF_TOKEN_ID = 'quiz_folder';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QuizFolderRepository $folders,
        private readonly QuizFolderManager $manager,
    ) {
    }

    #[Route(path: '/library/quiz/folders/new', name: 'app_library_quiz_folder_new', methods: ['POST'])]
    public function newFolder(Request $request): Response
    {
        $this->assertFolderCsrf($request, self::CSRF_TOKEN_ID);
        $parent = $this->parentFromRequest($request);
        $name = PostValue::trimmed($request, 'name');

        if ('' === $name) {
            $this->addFlash('error', 'quizFolderNameRequiredMessage');

            return $this->backToFolder($parent);
        }

        $folder = $this->manager->createFolder($this->currentUser(), $parent, $name);
        $this->entityManager->flush();
        $this->addFlash('success', 'quizFolderCreatedFlashMessage');

        return $this->backToFolder($folder->getParent());
    }

    // No `\d+` on folderId here, unlike the browse route, and it is not an oversight: the screen
    // generates this address as a template carrying a `__FOLDER_ID__` placeholder, and a numeric
    // requirement makes path() refuse to generate it at all - which throws while *rendering* and
    // puts the whole screen in 500. The id is cast and then looked up through the Voter, so nothing
    // rests on the pattern.
    #[Route(path: '/library/quiz/folders/{folderId}/rename', name: 'app_library_quiz_folder_rename', methods: ['POST'])]
    public function rename(Request $request, int $folderId): Response
    {
        $this->assertFolderCsrf($request, self::CSRF_TOKEN_ID);
        $folder = $this->loadFolder($this->folders, $folderId) ?? throw $this->createNotFoundException();
        $name = PostValue::trimmed($request, 'name');

        if ('' === $name) {
            $this->addFlash('error', 'quizFolderNameRequiredMessage');

            return $this->backToFolder($folder->getParent());
        }

        $this->manager->rename($folder, $name, $this->currentUser());
        $this->entityManager->flush();
        $this->addFlash('success', 'quizFolderRenamedFlashMessage');

        return $this->backToFolder($folder->getParent());
    }

    /**
     * A folder of the rail dropped onto another one.
     *
     * JSON rather than a redirect because it is a fetch and not a form - the screen reloads itself
     * once the answer is in, which is what keeps the rail, the listing and the breadcrumb in step
     * without any of them rebuilding the others.
     */
    // No `\d+`, same reason as rename() above: the rail generates this one as a template too.
    #[Route(path: '/library/quiz/folders/{folderId}/move', name: 'app_library_quiz_folder_move', methods: ['POST'])]
    public function move(Request $request, int $folderId): JsonResponse
    {
        $this->assertFolderCsrf($request, self::CSRF_TOKEN_ID);
        $folder = $this->loadFolder($this->folders, $folderId) ?? throw $this->createNotFoundException();

        if (!$this->manager->moveFolder($folder, $this->parentFromRequest($request), $this->currentUser())) {
            // A folder dropped into its own descendant. Refused rather than thrown: the drag came
            // from a browser, and the screen simply redraws where the folder still is.
            return $this->json(['error' => 'quizFolderMoveRefusedMessage'], Response::HTTP_CONFLICT);
        }

        $this->entityManager->flush();

        return $this->json(['moved' => true]);
    }

    /**
     * Deleting a folder, which **never deletes a quiz**: its content is promoted one level up and
     * the confirmation says so. A QuizTemplate is hard-deleted in this application and there is no
     * corbeille to fish one out of, so a folder must not be able to take one with it.
     */
    // No `\d+`: the row menu builds this address from a template too.
    #[Route(path: '/library/quiz/folders/{folderId}/delete', name: 'app_library_quiz_folder_delete', methods: ['POST'])]
    public function delete(Request $request, int $folderId): Response
    {
        $this->assertFolderCsrf($request, self::CSRF_TOKEN_ID);
        $folder = $this->loadFolder($this->folders, $folderId) ?? throw $this->createNotFoundException();
        $parent = $folder->getParent();

        $this->manager->delete($folder, $this->currentUser());
        $this->entityManager->flush();
        $this->addFlash('success', 'quizFolderDeletedFlashMessage');

        return $this->backToFolder($parent);
    }

    /**
     * The drag the whole feature exists for: a quiz row dropped onto a folder of the rail, or onto
     * the rail itself, which files it back at the root.
     */
    // No `\d+`: the listing generates this address from an `__ID__` template.
    #[Route(path: '/library/quiz/{id}/move', name: 'app_library_quiz_move', methods: ['POST'])]
    public function moveQuiz(Request $request, int $id, QuizTemplateRepository $quizzes): JsonResponse
    {
        $this->assertFolderCsrf($request, self::CSRF_TOKEN_ID);
        $quiz = $quizzes->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(QuizTemplateVoter::EDIT, $quiz);

        if (!$this->manager->moveQuiz($quiz, $this->parentFromRequest($request), $this->currentUser())) {
            return $this->json(['error' => 'quizFolderMoveRefusedMessage'], Response::HTTP_CONFLICT);
        }

        $this->entityManager->flush();

        return $this->json(['moved' => true]);
    }

    /** The folder named in the body - `parent=''` being the root, which is how a drop leaves one. */
    private function parentFromRequest(Request $request): ?QuizFolder
    {
        $parentId = PostValue::nullableInt($request, 'parent');

        if (null === $parentId) {
            return null;
        }

        $parent = $this->folders->find($parentId) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(QuizFolderVoter::EDIT, $parent);

        return $parent;
    }
}
