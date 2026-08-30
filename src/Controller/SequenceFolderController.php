<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\SequenceFolder;
use App\Entity\User;
use App\Enum\Feature;
use App\Repository\SequenceFolderRepository;
use App\Repository\SequenceTemplateRepository;
use App\Security\Voter\SequenceFolderVoter;
use App\Security\Voter\SequenceTemplateVoter;
use App\Service\PostValue;
use App\Service\SequenceFolderManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Everything that changes the classement of a sequence library: new folder, rename, move, delete,
 * and « Déplacer vers… » filing a séquence into one.
 *
 * A controller of its own rather than five more actions in the 900-line
 * App\Controller\SequenceLibraryController, per the repository's rule: when you touch a fat
 * controller, extract rather than extend. It is App\Controller\QuizFolderController's shape, which
 * is what makes the two classements one gesture rather than two.
 *
 * **Every POST redirects**, which is Turbo's rule here - the two exceptions are the moves, which
 * answer JSON to a fetch. The rules themselves are App\Service\SequenceFolderManager's.
 */
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
#[RequiresFeature(Feature::SequenceLibrary)]
class SequenceFolderController extends AbstractController
{
    use SequenceLibraryFolderTrait;

    private const string CSRF_TOKEN_ID = 'sequence_folder';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SequenceFolderRepository $folders,
        private readonly SequenceFolderManager $manager,
    ) {
    }

    #[Route(path: '/library/sequences/folders/new', name: 'app_library_sequences_folder_new', methods: ['POST'])]
    public function newFolder(Request $request): Response
    {
        $this->assertSequenceFolderCsrf($request, self::CSRF_TOKEN_ID);
        $parent = $this->parentFromRequest($request);
        $name = PostValue::trimmed($request, 'name');

        if ('' === $name) {
            $this->addFlash('error', 'sequenceFolderNameRequiredMessage');

            return $this->backToSequenceFolder($parent);
        }

        $folder = $this->manager->createFolder($this->folderUser(), $parent, $name);
        $this->entityManager->flush();
        $this->addFlash('success', 'sequenceFolderCreatedFlashMessage');

        return $this->backToSequenceFolder($folder->getParent());
    }

    // No `\d+` on folderId here, unlike the browse route, and it is not an oversight: the screen
    // generates this address as a template carrying a `__FOLDER_ID__` placeholder, and a numeric
    // requirement makes path() refuse to generate it at all - which throws while *rendering* and
    // puts the whole screen in 500. The id is cast and then looked up through the Voter, so nothing
    // rests on the pattern.
    #[Route(path: '/library/sequences/folders/{folderId}/rename', name: 'app_library_sequences_folder_rename', methods: ['POST'])]
    public function rename(Request $request, int $folderId): Response
    {
        $this->assertSequenceFolderCsrf($request, self::CSRF_TOKEN_ID);
        $folder = $this->loadSequenceFolder($this->folders, $folderId) ?? throw $this->createNotFoundException();
        $name = PostValue::trimmed($request, 'name');

        if ('' === $name) {
            $this->addFlash('error', 'sequenceFolderNameRequiredMessage');

            return $this->backToSequenceFolder($folder->getParent());
        }

        $this->manager->rename($folder, $name, $this->folderUser());
        $this->entityManager->flush();
        $this->addFlash('success', 'sequenceFolderRenamedFlashMessage');

        return $this->backToSequenceFolder($folder->getParent());
    }

    /**
     * A folder of the rail dropped onto another one.
     *
     * JSON rather than a redirect because it is a fetch and not a form - the screen reloads itself
     * once the answer is in, which is what keeps the rail, the listing and the breadcrumb in step
     * without any of them rebuilding the others.
     */
    // No `\d+`, same reason as rename() above: the rail generates this one as a template too.
    #[Route(path: '/library/sequences/folders/{folderId}/move', name: 'app_library_sequences_folder_move', methods: ['POST'])]
    public function move(Request $request, int $folderId): JsonResponse
    {
        $this->assertSequenceFolderCsrf($request, self::CSRF_TOKEN_ID);
        $folder = $this->loadSequenceFolder($this->folders, $folderId) ?? throw $this->createNotFoundException();

        if (!$this->manager->moveFolder($folder, $this->parentFromRequest($request), $this->folderUser())) {
            // A folder dropped into its own descendant. Refused rather than thrown: the drag came
            // from a browser, and the screen simply redraws where the folder still is.
            return $this->json(['error' => 'sequenceFolderMoveRefusedMessage'], Response::HTTP_CONFLICT);
        }

        $this->entityManager->flush();

        return $this->json(['moved' => true]);
    }

    /**
     * Deleting a folder, which **never deletes a séquence**: its content is promoted one level up
     * and the confirmation says so. A SequenceTemplate is hard-deleted in this application and there
     * is no corbeille to fish one out of, so a folder must not be able to take one with it.
     */
    // No `\d+`: the rail and the row menu build this address from a template too.
    #[Route(path: '/library/sequences/folders/{folderId}/delete', name: 'app_library_sequences_folder_delete', methods: ['POST'])]
    public function delete(Request $request, int $folderId): Response
    {
        $this->assertSequenceFolderCsrf($request, self::CSRF_TOKEN_ID);
        $folder = $this->loadSequenceFolder($this->folders, $folderId) ?? throw $this->createNotFoundException();
        $parent = $folder->getParent();

        $this->manager->delete($folder, $this->folderUser());
        $this->entityManager->flush();
        $this->addFlash('success', 'sequenceFolderDeletedFlashMessage');

        return $this->backToSequenceFolder($parent);
    }

    /**
     * Filing a séquence into a folder, from the listing's « Déplacer vers… ».
     *
     * There is no drag from a séquence row onto the rail, unlike the quiz and survey libraries, and
     * the reason is that the row already has a drag: the ⠿ handle reorders the folder's séquences by
     * hand. One row cannot mean two things while being dragged, so the classement takes the list and
     * leaves the handle alone.
     */
    // No `\d+`: the listing generates this address from an `__ID__` template.
    #[Route(path: '/library/sequences/{id}/move', name: 'app_library_sequences_move', methods: ['POST'])]
    public function moveSequence(Request $request, int $id, SequenceTemplateRepository $sequences): JsonResponse
    {
        $this->assertSequenceFolderCsrf($request, self::CSRF_TOKEN_ID);
        $sequence = $sequences->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(SequenceTemplateVoter::EDIT, $sequence);

        if (!$this->manager->moveSequence($sequence, $this->parentFromRequest($request), $this->folderUser())) {
            return $this->json(['error' => 'sequenceFolderMoveRefusedMessage'], Response::HTTP_CONFLICT);
        }

        $this->entityManager->flush();

        return $this->json(['moved' => true]);
    }

    /** The folder named in the body - `parent=''` being the root, which is how a drop leaves one. */
    private function parentFromRequest(Request $request): ?SequenceFolder
    {
        $parentId = PostValue::nullableInt($request, 'parent');

        if (null === $parentId) {
            return null;
        }

        $parent = $this->folders->find($parentId) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(SequenceFolderVoter::EDIT, $parent);

        return $parent;
    }

    private function folderUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
