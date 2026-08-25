<?php

declare(strict_types=1);

namespace App\Controller\FileLibrary;

use App\Attribute\RequiresFeature;
use App\Entity\FileLibraryNode;
use App\Enum\Feature;
use App\Repository\FileLibraryNodeRepository;
use App\Security\Voter\FileLibraryVoter;
use App\Service\FileLibraryNodeManager;
use App\Service\FileLibraryQuota;
use App\Service\FileLibraryUploadValidator;
use App\Service\PostValue;
use App\Service\StagedUpload;
use App\Service\StagedUploadStore;
use App\Service\UploadIntake;
use App\Validator\AllowedUpload;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Taking a file into the library, and replacing one (design/validated/file-library.md).
 *
 * It is the second endpoint of the staged-upload family, and the difference from `/uploads/stage` is
 * the one the specification names: **the answer is the created row**, because the library has no
 * form to submit afterwards. The bytes still travel the same way - the picker sends them to
 * `/uploads/stage` first, and this claims the token.
 *
 * Three checks, in this order, and the order is the design:
 *
 * 1. the **platform policy** with the per-file ceiling of App\Service\FileLibraryUploadValidator -
 *    200 Mo for a video, 20 Mo otherwise. The library narrows nothing else: it is the one place that
 *    accepts everything the platform accepts, exactly as the wiki does;
 * 2. the **quota**, server-side and late on purpose - PHP has the whole body before any controller
 *    runs, so a pre-flight check saves no bandwidth. The refusal carries the numbers;
 * 3. the **name**, which is where *Remplacer* / *Conserver les deux* is answered: re-uploading a
 *    name that already exists in the folder is neither an error nor silently renamed.
 */
#[IsGranted(FileLibraryVoter::EDIT)]
#[Route(path: '/tools/file-library')]
#[RequiresFeature(Feature::FileLibrary)]
class FileLibraryUploadController extends AbstractController
{
    use FileLibraryTrait;

    private const string CSRF_TOKEN_ID = 'file_library_node';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FileLibraryNodeRepository $nodes,
        private readonly FileLibraryNodeManager $manager,
        private readonly FileLibraryQuota $quota,
        private readonly FileLibraryUploadValidator $uploadValidator,
        private readonly StagedUploadStore $stagedUploads,
        private readonly UploadIntake $intake,
        private readonly ValidatorInterface $validator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/upload', name: 'app_file_library_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $this->assertCsrf($request);
        $owner = $this->currentUser();

        $staged = $this->resolveStaged($request);

        if ($staged instanceof JsonResponse) {
            return $staged;
        }

        $folder = $this->folderFromRequest($request);
        $refusal = $this->refuse($staged, $owner);

        if (null !== $refusal) {
            return $refusal;
        }

        // "Remplacer" or "Conserver les deux", asked rather than decided: the browser sends the
        // answer back with the second attempt, and the default - no answer - keeps both.
        $existing = $this->nodes->findSiblingNamed($owner, $folder, $staged->originalName);
        $onConflict = PostValue::trimmed($request, 'onConflict');

        if (null !== $existing && $existing->isFile() && 'replace' === $onConflict) {
            $key = $this->intake->store($staged, FileLibraryNodeManager::UPLOAD_PREFIX, $this->storageName($staged));
            $this->manager->replace($existing, $key, $staged->originalName, $staged->mimeType, $staged->size, $owner);
            $this->entityManager->flush();

            return $this->json(['node' => $this->nodeJson($existing), 'replaced' => true]);
        }

        if (null !== $existing && '' === $onConflict) {
            // Nothing is written yet: the staged object keeps its own one-day fuse, so a teacher who
            // closes the dialog leaves nothing behind.
            return $this->json([
                'conflict' => [
                    'name' => $staged->originalName,
                    'nodeId' => $existing->getId(),
                    'message' => $this->translator->trans('fileLibraryNameConflictMessage', ['%name%' => $staged->originalName]),
                ],
            ], Response::HTTP_CONFLICT);
        }

        $key = $this->intake->store($staged, FileLibraryNodeManager::UPLOAD_PREFIX, $this->storageName($staged));
        $node = $this->manager->createFile(
            $owner,
            $folder,
            $staged->originalName,
            $key,
            $staged->originalName,
            $staged->mimeType,
            $staged->size,
            PostValue::int($request, 'duration') ?: null,
        );
        $this->entityManager->flush();

        return $this->json(['node' => $this->nodeJson($node)]);
    }

    /**
     * *Remplacer* on a node that already exists: a new object under the same id, so every link keeps
     * pointing at the right thing.
     */
    // No `\d+` on nodeId: the screen generates this address as a template carrying `__NODE_ID__`,
    // and a numeric requirement makes path() refuse to generate it - a 500 while rendering rather
    // than a 404 on use. See FileLibraryNodeController::rename() for the whole note.
    #[Route(path: '/{nodeId}/replace', name: 'app_file_library_node_replace', methods: ['POST'])]
    public function replace(Request $request, int $nodeId): JsonResponse
    {
        $this->assertCsrf($request);
        $owner = $this->currentUser();
        $node = $this->loadNode($this->nodes, $nodeId, FileLibraryVoter::EDIT);

        if (null === $node || !$node->isFile()) {
            throw $this->createNotFoundException();
        }

        $staged = $this->resolveStaged($request);

        if ($staged instanceof JsonResponse) {
            return $staged;
        }

        // The file being replaced stops counting first: replacing a 40 Mo PDF with a 45 Mo one must
        // not be refused by the 40 Mo it is about to release.
        $refusal = $this->refuse($staged, $owner, $node->getSizeBytes() ?? 0);

        if (null !== $refusal) {
            return $refusal;
        }

        $key = $this->intake->store($staged, FileLibraryNodeManager::UPLOAD_PREFIX, $this->storageName($staged));
        $this->manager->replace($node, $key, $staged->originalName, $staged->mimeType, $staged->size, $owner);
        $this->entityManager->flush();

        return $this->json(['node' => $this->nodeJson($node), 'replaced' => true]);
    }

    /** The token, resolved and re-checked - or the JSON refusal to hand straight back. */
    private function resolveStaged(Request $request): StagedUpload|JsonResponse
    {
        $staged = $this->stagedUploads->resolve(PostValue::string($request, 'token'), (int) $this->currentUser()->getId());

        if (null === $staged) {
            return $this->json(['error' => 'filePickerInvalidTokenMessage', 'message' => $this->translator->trans('filePickerInvalidTokenMessage')], Response::HTTP_BAD_REQUEST);
        }

        return $staged;
    }

    /**
     * The two refusals, in the order a teacher can act on them: what the file *is*, then whether
     * there is room for it.
     */
    private function refuse(StagedUpload $staged, \App\Entity\User $owner, int $releasedBytes = 0): ?JsonResponse
    {
        $violations = $this->validator->validate($staged, new AllowedUpload($this->uploadValidator->policyFor($staged->originalName)));

        if ($violations->count() > 0) {
            $violation = $violations->get(0);

            return $this->json(['error' => (string) $violation->getCode(), 'message' => (string) $violation->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        if ($staged->size - $releasedBytes > $this->quota->remainingBytes($owner)) {
            $refusal = $this->quota->refusal($owner, $staged->size);

            return $this->json([
                'error' => $refusal['key'],
                'message' => $this->translator->trans($refusal['key'], $refusal['parameters']),
            ], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        return null;
    }

    private function folderFromRequest(Request $request): ?FileLibraryNode
    {
        $folderId = PostValue::nullableInt($request, 'folder');

        if (null === $folderId) {
            return null;
        }

        $folder = $this->nodes->find($folderId) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(FileLibraryVoter::EDIT, $folder);

        return $folder;
    }

    /** The name in the bucket: generated, never the teacher's - two "cours.pdf" must not collide. */
    private function storageName(StagedUpload $staged): string
    {
        $extension = UploadIntake::extension($staged);

        return '' === $extension
            ? bin2hex(random_bytes(16))
            : \sprintf('%s.%s', bin2hex(random_bytes(16)), $extension);
    }

    /** @return array<string, mixed> */
    private function nodeJson(FileLibraryNode $node): array
    {
        return [
            'id' => $node->getId(),
            'name' => $node->getName(),
            'size' => $node->getSizeBytes(),
            'type' => $node->getType()->value,
        ];
    }

    private function assertCsrf(Request $request): void
    {
        $token = $request->headers->get('X-CSRF-Token') ?? $request->request->get('_token');

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, \is_string($token) ? $token : null)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
