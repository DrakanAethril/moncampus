<?php

declare(strict_types=1);

namespace App\Controller\Wiki;

use App\Entity\User;
use App\Entity\Wiki;
use App\Entity\WikiAttachment;
use App\Form\WikiAttachmentType;
use App\Repository\WikiNodeRepository;
use App\Repository\WikiRepository;
use App\Security\Voter\WikiVoter;
use App\Service\ClamAvUnavailableException;
use App\Service\FileUploadService;
use App\Service\InfectedUploadException;
use App\Service\PostValue;
use App\Service\StagedUpload;
use App\Service\UploadIntake;
use App\Service\UploadPolicy;
use App\Service\WikiTree;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * What a page carries besides its text: attachments, the images pasted into its body, and the list
 * of pages the internal-link picker offers.
 *
 * The wiki is the one upload field on the platform that narrows the rule by **nothing** - it is the
 * general-purpose workspace, so `UploadPolicy::platform()` is its policy (see
 * design/validated/upload-policy.md). Everything still goes through the same scanner as every other
 * upload; a file that reaches the bucket has been scanned.
 */
#[IsGranted(new Expression('is_granted("ROLE_USER") and not is_granted("ROLE_TUTOR") and not is_granted("ROLE_EXTERNAL")'))]
#[Route(path: '/wiki/{id}', requirements: ['id' => '\d+'])]
class WikiContentController extends AbstractController
{
    use WikiTrait;

    private const string ATTACHMENT_PREFIX = 'wiki/';

    // Images pasted into a page's body, kept apart from its attachments: they are referenced by URL
    // from the HTML rather than listed anywhere.
    private const string IMAGE_UPLOAD_PREFIX = 'wiki/images/';

    /**
     * Raster formats only for an inline image, deliberately narrower than what the page's
     * attachments accept: an SVG is a document that can carry script, and this one is served from
     * the uploads bucket under a URL a reader opens directly.
     */
    private const array INLINE_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WikiRepository $wikis,
        private readonly WikiNodeRepository $nodes,
        private readonly WikiTree $tree,
        private readonly FileUploadService $uploads,
    ) {
    }

    #[Route(path: '/p/{nodeId}/attachments', name: 'app_wiki_attachment_add', requirements: ['nodeId' => '\d+'], methods: ['POST'])]
    public function addAttachments(Request $request, int $id, int $nodeId, UploadIntake $uploadIntake): Response
    {
        $wiki = $this->editableWiki($id);
        $node = $this->loadNode($wiki, $nodeId);
        $user = $this->currentUser();

        // A form type since the fifteenth upload field of the platform stopped carrying bytes
        // (App\Form\FilePickerType): the CSRF token this route used to check by hand is the form's
        // own now, the platform policy is the field's, and the antivirus ran at staging time - so
        // the three refusal paths that used to live here are gone rather than moved.
        $form = $this->createForm(WikiAttachmentType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('danger', $error->getMessage());
            }

            return $this->redirectToRoute('app_wiki_page', ['id' => $id, 'nodeId' => $nodeId]);
        }

        /** @var list<StagedUpload> $files */
        $files = $form->get('files')->getData();
        $position = \count($node->getAttachments());
        $accepted = 0;

        foreach ($files as $file) {
            $key = $uploadIntake->store(
                $file,
                self::ATTACHMENT_PREFIX,
                \sprintf('%s.%s', bin2hex(random_bytes(16)), UploadIntake::extension($file)),
            );

            $node->addAttachment(
                (new WikiAttachment(UploadIntake::originalName($file), $key))
                    ->setMimeType(UploadIntake::mimeType($file))
                    ->setSizeBytes(UploadIntake::size($file))
                    ->setPosition($position++),
            );
            ++$accepted;
        }

        if ($accepted > 0) {
            $node->touch($user);
            $wiki->touch();
            $this->entityManager->flush();
            $this->addFlash('success', 'wikiAttachmentsAddedFlashMessage');
        }

        return $this->redirectToRoute('app_wiki_page', ['id' => $id, 'nodeId' => $nodeId]);
    }

    #[Route(path: '/p/{nodeId}/attachments/{attachmentId}/delete', name: 'app_wiki_attachment_delete', requirements: ['nodeId' => '\d+', 'attachmentId' => '\d+'], methods: ['POST'])]
    public function deleteAttachment(Request $request, int $id, int $nodeId, int $attachmentId): Response
    {
        $wiki = $this->editableWiki($id);
        $this->assertToken($request, 'wiki_attachment');
        $node = $this->loadNode($wiki, $nodeId);

        foreach ($node->getAttachments() as $attachment) {
            if ($attachment->getId() === $attachmentId) {
                $this->uploads->delete($attachment->getStorageKey());
                $node->removeAttachment($attachment);
                $this->entityManager->remove($attachment);
                $this->entityManager->flush();

                break;
            }
        }

        return $this->redirectToRoute('app_wiki_page', ['id' => $id, 'nodeId' => $nodeId]);
    }

    /**
     * The editor's image button. HugeRTE posts one file and expects {"location": "<url>"} back,
     * which it writes into the body as an <img src> - exactly the contract the Base documentaire's
     * own endpoint already answers.
     *
     * The picture is deliberately not tracked as a row: deleting a page does not chase the images
     * its body points at, the same trade every WYSIWYG upload in this app makes.
     */
    #[Route(path: '/images', name: 'app_wiki_image_upload', methods: ['POST'])]
    public function uploadImage(Request $request, int $id): JsonResponse
    {
        $wiki = $this->wikis->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(WikiVoter::EDIT, $wiki);

        if (!$this->isCsrfTokenValid('wiki_image', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile) {
            return $this->json(['error' => 'No file received.'], Response::HTTP_BAD_REQUEST);
        }

        $name = $file->getClientOriginalName();
        $policy = UploadPolicy::platform()->restrictTo(...self::INLINE_IMAGE_EXTENSIONS);

        if (!$policy->accepts($name, $file->getMimeType())) {
            return $this->json(['error' => 'Unsupported image type.'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        try {
            $key = $this->uploads->upload(
                self::IMAGE_UPLOAD_PREFIX,
                \sprintf('%s.%s', bin2hex(random_bytes(16)), pathinfo(mb_strtolower($name), \PATHINFO_EXTENSION)),
                $file,
            );
        } catch (InfectedUploadException) {
            return $this->json(['error' => 'Infected file.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ClamAvUnavailableException) {
            return $this->json(['error' => 'Scanner unavailable.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->json(['location' => $this->uploads->url($key)]);
    }

    /**
     * The internal-link picker's data: every live page of this wiki, in reading order, with the URL
     * a link should point at.
     *
     * This is the endpoint; the toolbar button that opens it arrives with the wiki's own editor.
     * Linking one page to another is what makes this a wiki rather than a pile of pages - without
     * it the rest is decoration.
     */
    #[Route(path: '/pages', name: 'app_wiki_pages', methods: ['GET'])]
    public function pages(int $id): JsonResponse
    {
        $wiki = $this->loadWiki($id);
        $rail = $this->rail($wiki);
        $results = [];

        $this->flatten($rail['tree'], $wiki, $results);

        return $this->json(['results' => $results]);
    }

    /**
     * The lock heartbeat: every 60 seconds while somebody has the editor open. A lock nobody
     * refreshes goes stale after five minutes, which is what stops an abandoned tab from leaving a
     * page nobody dares edit.
     */
    #[Route(path: '/p/{nodeId}/lock', name: 'app_wiki_page_lock', requirements: ['nodeId' => '\d+'], methods: ['POST'])]
    public function heartbeat(Request $request, int $id, int $nodeId): JsonResponse
    {
        $wiki = $this->wikis->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(WikiVoter::EDIT, $wiki);

        if (!$this->isCsrfTokenValid('wiki_lock', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        $node = $this->loadNode($wiki, $nodeId);
        $user = $this->currentUser();

        if ('release' === PostValue::string($request, 'action')) {
            if ($node->getLockedBy() === $user) {
                $node->releaseLock();
                $this->entityManager->flush();
            }

            return $this->json(['held' => false]);
        }

        // Taking over is allowed on purpose: the lock removes the silent overwrite, it does not
        // prevent anything. Whoever is still in there sees the banner change on their next beat.
        $node->lockFor($user);
        $this->entityManager->flush();

        return $this->json(['held' => true]);
    }

    /**
     * @param list<array<string, mixed>>                                   $branch
     * @param list<array{id: int, title: string, url: string, depth: int}> $results
     *
     * @param-out list<array{id: int, title: string, url: string, depth: int}> $results
     */
    private function flatten(array $branch, Wiki $wiki, array &$results): void
    {
        foreach ($branch as $row) {
            /** @var \App\Entity\WikiNode $node */
            $node = $row['node'];
            $nodeId = $node->getId();

            if (null === $nodeId) {
                continue;
            }

            $results[] = [
                'id' => $nodeId,
                'title' => $node->getTitle(),
                'url' => $this->generateUrl('app_wiki_page', ['id' => $wiki->getId(), 'nodeId' => $nodeId]),
                'depth' => $node->getDepth(),
            ];

            /** @var list<array<string, mixed>> $children */
            $children = $row['children'];
            $this->flatten($children, $wiki, $results);
        }
    }

    private function editableWiki(int $id): Wiki
    {
        $wiki = $this->wikis->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(WikiVoter::EDIT, $wiki);

        return $wiki;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
