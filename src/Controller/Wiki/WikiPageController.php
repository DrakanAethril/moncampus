<?php

declare(strict_types=1);

namespace App\Controller\Wiki;

use App\Attribute\RequiresFeature;
use App\Entity\User;
use App\Enum\Feature;
use App\Form\WikiAttachmentType;
use App\Repository\WikiNodeRepository;
use App\Repository\WikiRepository;
use App\Repository\WikiRevisionRepository;
use App\Security\Voter\WikiVoter;
use App\Service\PostValue;
use App\Service\WikiNodeManager;
use App\Service\WikiPageOutline;
use App\Service\WikiTree;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Reading and writing one page: the rail on the left, the page in the middle.
 *
 * A folder is opened here too, and renders its own body when it carries one - the "everything is a
 * page that can have children" half of the model. A folder with no body shows what it holds.
 */
#[IsGranted(new Expression('is_granted("ROLE_USER") and not is_granted("ROLE_TUTOR") and not is_granted("ROLE_EXTERNAL")'))]
#[Route(path: '/wiki/{id}/p', requirements: ['id' => '\d+'])]
#[RequiresFeature(Feature::Wiki)]
class WikiPageController extends AbstractController
{
    use WikiTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WikiRepository $wikis,
        private readonly WikiNodeRepository $nodes,
        private readonly WikiTree $tree,
        private readonly WikiNodeManager $nodeManager,
        private readonly WikiRevisionRepository $revisions,
        private readonly WikiPageOutline $outline,
    ) {
    }

    #[Route(path: '/{nodeId}', name: 'app_wiki_page', requirements: ['nodeId' => '\d+'], methods: ['GET'])]
    public function page(int $id, int $nodeId): Response
    {
        $wiki = $this->loadWiki($id);
        $node = $this->loadNode($wiki, $nodeId);

        if ($node->isDeleted()) {
            throw $this->createNotFoundException();
        }

        $rail = $this->rail($wiki);
        // Derived at read time, never stored: a writer who renames a heading has nothing else to
        // update, and the anchors the sommaire links to are stamped in the same pass.
        $outline = $this->outline->build($node->getBody());

        return $this->render('wiki/page.html.twig', [
            'wiki' => $wiki,
            'node' => $node,
            'tree' => $rail['tree'],
            'ancestors' => $this->nodeManager->ancestorsOf($node, $rail['byId']),
            'body' => '' === $outline['html'] ? $node->getBody() : $outline['html'],
            'outline' => $outline['entries'],
            // The attachments block posts to WikiContentController::addAttachments(), which now
            // reads this form rather than a raw `attachments[]` - see App\Form\WikiAttachmentType.
            'attachmentForm' => $this->createForm(WikiAttachmentType::class),
        ]);
    }

    #[Route(path: '/{nodeId}/edit', name: 'app_wiki_page_edit', requirements: ['nodeId' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        #[Target('app.wiki_page_body')] HtmlSanitizerInterface $sanitizer,
        int $id,
        int $nodeId,
    ): Response {
        $wiki = $this->loadWiki($id);
        $this->denyAccessUnlessGranted(WikiVoter::EDIT, $wiki);
        $node = $this->loadNode($wiki, $nodeId);
        $user = $this->getUser();

        if ($node->isDeleted() || !$user instanceof User) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            $this->assertToken($request, 'wiki_page_edit');

            // Recorded before anything changes, so a revision is what the page *was* - which is
            // what makes "restaurer" mean something.
            $this->revisions->record($node, $user);

            $title = PostValue::trimmed($request, 'title');

            if ('' !== $title && $title !== $node->getTitle()) {
                $this->nodeManager->rename($node, $title, $user);
            }

            $body = PostValue::string($request, 'body');
            $this->nodeManager->writeBody($node, '' === trim($body) ? null : $sanitizer->sanitize($body), $user);

            // Leaving the editor hands the page back: the lock exists to stop a silent overwrite,
            // not to survive the save that ends it.
            $node->releaseLock();
            $this->entityManager->flush();

            $this->addFlash('success', 'wikiPageSavedFlashMessage');

            return $this->redirectToRoute('app_wiki_page', ['id' => $id, 'nodeId' => $nodeId]);
        }

        $rail = $this->rail($wiki);
        // Read *before* taking the lock, or the editor would always report itself as the holder.
        $heldBy = $node->isLockedFor($user) ? $node->getLockedBy() : null;
        $lockedSince = $node->getLockedAt();

        // Opening the editor takes the lock, take-over included: the banner tells the second person
        // what is happening, it does not stop them.
        $node->lockFor($user);
        $this->entityManager->flush();

        return $this->render('wiki/page_edit.html.twig', [
            'wiki' => $wiki,
            'node' => $node,
            'tree' => $rail['tree'],
            'ancestors' => $this->nodeManager->ancestorsOf($node, $rail['byId']),
            'lockedBy' => $heldBy,
            'lockedSince' => $heldBy ? $lockedSince : null,
        ]);
    }
}
